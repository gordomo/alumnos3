<?php

namespace App\Controller;

use App\Entity\BillingInvoice;
use App\Service\MercadoPagoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Lo que Mercado Pago nos avisa cuando un pago cambia de estado.
 *
 * Es público y sin sesión porque lo llama Mercado Pago, no una persona. Lo que lo hace seguro no
 * es quién llama sino qué se hace con lo que dice: del aviso se toma únicamente un id de pago, y
 * después le preguntamos a Mercado Pago por ese id con nuestro propio token. Si alguien inventa
 * un aviso, lo único que puede lograr es que consultemos un pago que no existe, o uno real de
 * nuestra cuenta que ya está aprobado igual. Nada de lo que viene en el cuerpo se cree.
 *
 * Siempre se contesta 200, incluso ante un error nuestro: un código distinto hace que Mercado
 * Pago reintente el mismo aviso durante días. Lo que falla queda en el log.
 */
class MercadoPagoWebhookController extends AbstractController
{
    /**
     * @Route("/webhook/mercadopago", name="billing_invoice_webhook_mp", methods={"POST", "GET"})
     */
    public function recibir(
        Request $request,
        MercadoPagoService $mercadoPago,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ): Response {
        $pagoId = $this->idDelPago($request);

        if (!$pagoId) {
            // Mercado Pago manda también avisos de otros temas (contracargos, planes). No son
            // para nosotros y no son un error.
            return new Response('ignorado', Response::HTTP_OK);
        }

        try {
            $pago = $mercadoPago->buscarPago($pagoId);
        } catch (\Throwable $e) {
            $logger->error('Webhook de Mercado Pago: no se pudo leer el pago', [
                'pago' => $pagoId,
                'error' => $e->getMessage(),
            ]);

            return new Response('error al consultar', Response::HTTP_OK);
        }

        $facturaId = MercadoPagoService::facturaIdDeReferencia($pago['external_reference'] ?? null);
        $factura = $facturaId ? $em->getRepository(BillingInvoice::class)->find($facturaId) : null;

        if (!$factura) {
            $logger->warning('Webhook de Mercado Pago: pago sin factura conocida', [
                'pago' => $pagoId,
                'referencia' => $pago['external_reference'] ?? null,
            ]);

            return new Response('sin factura', Response::HTTP_OK);
        }

        if (($pago['status'] ?? null) !== 'approved') {
            // Pendiente, en revisión o rechazado: no se toca la factura. Si después se aprueba,
            // Mercado Pago vuelve a avisar.
            $logger->info('Webhook de Mercado Pago: pago no aprobado', [
                'pago' => $pagoId,
                'factura' => $factura->getId(),
                'estado' => $pago['status'] ?? null,
            ]);

            return new Response('no aprobado', Response::HTTP_OK);
        }

        if ($factura->isPaid()) {
            // Mercado Pago reenvía el mismo aviso varias veces: la segunda no tiene que hacer nada.
            return new Response('ya estaba paga', Response::HTTP_OK);
        }

        $factura->setStatus('paid');
        $factura->setPaidAt(new \DateTime());
        $factura->setPaymentMethod('mercadopago');
        $factura->setMpPaymentId((string) $pagoId);
        $factura->setUpdatedAt(new \DateTime());
        $em->flush();

        $logger->info('Webhook de Mercado Pago: factura cancelada', [
            'pago' => $pagoId,
            'factura' => $factura->getId(),
            'instituto' => $factura->getInstituto()?->getNombre(),
        ]);

        return new Response('ok', Response::HTTP_OK);
    }

    /**
     * El id del pago, que Mercado Pago manda de varias formas según el tipo de aviso.
     *
     * Solo interesan los de tipo "payment". Los de "merchant_order" traen el mismo pago por otro
     * camino y procesarlos sería hacer dos veces lo mismo.
     */
    private function idDelPago(Request $request): ?string
    {
        $cuerpo = json_decode((string) $request->getContent(), true);
        $cuerpo = is_array($cuerpo) ? $cuerpo : [];

        $tipo = $cuerpo['type'] ?? $cuerpo['topic']
            ?? $request->query->get('type') ?? $request->query->get('topic');

        if ($tipo !== 'payment') {
            return null;
        }

        // Mercado Pago manda el id como "data.id" en la query. PHP convierte los puntos de los
        // nombres de parámetros en guiones bajos, así que $request->query->get('data.id') es
        // siempre null y hay que leerlo del query string crudo. Sin esto el webhook ignora
        // todos los avisos en silencio y ninguna factura se cancela sola.
        $id = $cuerpo['data']['id'] ?? $cuerpo['id']
            ?? $this->delQueryString($request, 'data.id')
            ?? $request->query->get('id');

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /**
     * Lee un parámetro del query string sin que PHP le toque el nombre.
     */
    private function delQueryString(Request $request, string $nombre): ?string
    {
        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $par) {
            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');

            if (urldecode($clave) === $nombre) {
                return urldecode($valor);
            }
        }

        return null;
    }
}
