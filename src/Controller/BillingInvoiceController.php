<?php

namespace App\Controller;

use App\Entity\BillingInvoice;
use App\Repository\BillingInvoiceRepository;
use App\Service\BillingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Service\MercadoPagoService;
use App\Service\SuscripcionService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * @Route("/instituto/facturas")
 */
class BillingInvoiceController extends AbstractController
{
    private BillingService $billingService;
    private SluggerInterface $slugger;

    public function __construct(
        BillingService $billingService,
        SluggerInterface $slugger,
        private \App\Service\SuscripcionService $suscripcionService,
        private \App\Repository\BillingConfigRepository $billingConfigRepository
    ) {
        $this->billingService = $billingService;
        $this->slugger = $slugger;
    }

    /**
     * @Route("/", name="billing_invoice_index", methods={"GET"})
     */
    public function index(): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if (!$instituto) {
            $this->addFlash('danger', 'No tienes un instituto asociado.');
            return $this->redirectToRoute('dashboard_index');
        }

        return $this->render('billing_invoice/index.html.twig', [
            'instituto' => $instituto,
            // El estado manda: de él salen el encabezado, el aviso y qué se puede hacer.
            'suscripcion' => $this->suscripcionService->estado($instituto),
            'historial' => $this->billingService->getBillingHistory($instituto, 24),
            'alumnosActivos' => $this->billingService->getActiveStudentsCount($instituto),
            'precioPorAlumno' => $this->suscripcionService->precioPorAlumno($instituto),
            'proximoMes' => $this->suscripcionService->montoEstimado($instituto),
            'config' => $this->billingConfigRepository->getOrCreatePriceConfig(),
        ]);
    }

    /**
     * @Route("/{id}", name="billing_invoice_show", methods={"GET"})
     */
    public function show(BillingInvoice $billingInvoice): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        return $this->render('billing_invoice/show.html.twig', [
            'invoice' => $billingInvoice,
            'instituto' => $instituto,
        ]);
    }

    /**
     * @Route("/{id}/pdf", name="billing_invoice_pdf", methods={"GET"})
     */
    public function pdf(BillingInvoice $billingInvoice): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        $html = $this->renderView('billing_invoice/pdf.html.twig', [
            'invoice' => $billingInvoice,
            'instituto' => $instituto,
        ]);

        $response = new Response($html);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="factura-' . $billingInvoice->getId() . '.pdf"');

        return $response;
    }

    /**
     * Las facturas que el instituto eligió pagar.
     *
     * Llegan como una lista de ids desde los checkboxes de la pantalla. Se filtran contra las
     * impagas del propio instituto: lo que viene del formulario decide cuáles de las suyas, no
     * cuáles existen. Si no vino ninguna, se usa la que encabeza la pantalla.
     *
     * @return BillingInvoice[]
     */
    private function facturasSeleccionadas(
        Request $request,
        BillingInvoice $porDefecto,
        SuscripcionService $suscripcionService
    ): array {
        $instituto = $porDefecto->getInstituto();
        $pedidas = array_map('intval', (array) $request->request->all('facturas'));

        if (!$pedidas) {
            return [$porDefecto];
        }

        $propias = array_filter(
            $suscripcionService->estado($instituto)['impagas'],
            static fn(BillingInvoice $f) => !$f->isPaid() && in_array($f->getId(), $pedidas, true)
        );

        return $propias ? array_values($propias) : [$porDefecto];
    }

    /**
     * @Route("/{id}/pay", name="billing_invoice_pay", methods={"POST"})
     */
    public function pay(
        Request $request,
        BillingInvoice $billingInvoice,
        EntityManagerInterface $entityManager,
        SuscripcionService $suscripcionService
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para pagar esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        // Verificar que la factura está pendiente o rechazada (para permitir reenvío)
        if (!$billingInvoice->isPending() && !$billingInvoice->isRejected()) {
            $this->addFlash('warning', 'Esta factura ya ha sido procesada.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Verificar CSRF token
        if (!$this->isCsrfTokenValid('pay_invoice_' . $billingInvoice->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Con qué dice que pagó. Determina si el comprobante es obligatorio.
        $metodo = (string) $request->request->get('payment_method', 'transferencia');
        if (!in_array($metodo, ['transferencia', 'cobrador'], true)) {
            $metodo = 'transferencia';
        }

        $facturas = $this->facturasSeleccionadas($request, $billingInvoice, $suscripcionService);

        $proofFile = $request->files->get('payment_proof');

        // En transferencia el comprobante es obligatorio; cuando pide que pase un cobrador
        // todavía no pagó nada, así que no hay comprobante posible.
        if (!$proofFile && $metodo === 'transferencia') {
            $this->addFlash('danger', 'Para una transferencia necesitamos el comprobante.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        // Validar el archivo, si vino uno.
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        if ($proofFile && !in_array($proofFile->getMimeType(), $allowedMimes)) {
            $this->addFlash('danger', 'El archivo debe ser una imagen (JPG, PNG) o PDF.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Validar tamaño (máximo 5MB)
        if ($proofFile && $proofFile->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('danger', 'El archivo es demasiado grande. Máximo 5MB.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        try {
            if ($proofFile) {
                // Nombre generado por el servidor: el original lo controla quien sube el archivo.
                $originalFilename = pathinfo($proofFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $this->slugger->slug($originalFilename);
                $newFilename = 'invoice-' . $billingInvoice->getId() . '-' . $safeFilename . '-' . uniqid() . '.' . $proofFile->guessExtension();

                $proofsDirectory = $this->getParameter('payment_proofs_directory');
                if (!is_dir($proofsDirectory)) {
                    mkdir($proofsDirectory, 0755, true);
                }

                $proofFile->move($proofsDirectory, $newFilename);
                $newFilename = $newFilename;
            } else {
                $newFilename = null;
            }

            $comentario = trim((string) $request->request->get('notas'));

            // Una declaración puede cubrir varias facturas: un instituto hace una transferencia
            // por el total, no una por mes. El mismo comprobante queda apuntado en todas.
            foreach ($facturas as $factura) {
                if ($newFilename) {
                    $factura->setPaymentProofPath($newFilename);
                }

                $factura->setPaymentMethod($metodo);

                // El comentario del instituto se agrega a las notas sin borrar lo que ya hubiera.
                if ($comentario !== '') {
                    $factura->setNotes(trim(($factura->getNotes() ?? '') . "\n" . sprintf(
                        '[%s] %s: %s',
                        (new \DateTime())->format('d/m/Y'),
                        $metodo,
                        $comentario
                    )));
                }

                $factura->setPaymentRequestedAt(new \DateTime());
                $factura->setStatus('pending_approval');
                // Limpiar datos de rechazo si existían
                $factura->setRejectionReason(null);
                $factura->setRejectedAt(null);
                $factura->setUpdatedAt(new \DateTime());
            }

            $entityManager->flush();

            $this->addFlash('success', $metodo === 'cobrador'
                ? sprintf('Listo: anotamos que pase un cobrador por %d factura(s). Te vamos a contactar para coordinar.', count($facturas))
                : sprintf('Recibimos tu comprobante por %d factura(s). En cuanto lo confirmemos te va a figurar como pagado.', count($facturas)));
            
        } catch (FileException $e) {
            $this->addFlash('danger', 'Error al subir el comprobante. Por favor, intenta nuevamente.');
        }
        
        return $this->redirectToRoute('billing_invoice_index');
    }

    /**
     * Manda al instituto a pagar su factura con Mercado Pago.
     *
     * El link se pide en el momento y no se guarda: una preferencia vieja podría tener un monto
     * que ya no corresponde, y el riesgo de cobrar de menos es peor que la llamada de más.
     *
     * @Route("/{id}/mercadopago", name="billing_invoice_mercadopago", methods={"POST"})
     */
    public function mercadoPago(
        Request $request,
        BillingInvoice $invoice,
        MercadoPagoService $mercadoPago,
        UrlGeneratorInterface $urlGenerator,
        SuscripcionService $suscripcionService
    ): Response {
        $instituto = $this->getUser()->getInstituto();

        if (!$instituto || $invoice->getInstituto() !== $instituto) {
            throw $this->createNotFoundException('Factura no encontrada');
        }

        if (!$this->isCsrfTokenValid('mercadopago' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

            return $this->redirectToRoute('billing_invoice_index');
        }

        if ($invoice->isPaid()) {
            $this->addFlash('info', 'Esa factura ya está paga.');

            return $this->redirectToRoute('billing_invoice_index');
        }

        // Se cobra lo que el instituto haya marcado: una sola preferencia con una línea por
        // factura, así en el checkout ve qué meses está pagando.
        $facturas = $this->facturasSeleccionadas($request, $invoice, $suscripcionService);

        // Las dos URLs se arman sobre APP_URL y no sobre el request: detrás del nginx del
        // servidor el pedido llega por http plano, Symfony genera http:// y Mercado Pago
        // descarta esas URLs, así que el pago se podía hacer pero nunca volvía ni avisaba.
        $base = rtrim((string) $this->getParameter('app.url'), '/');

        try {
            $link = $mercadoPago->crearLinkDePago(
                $facturas,
                $base . $urlGenerator->generate('billing_invoice_index'),
                $base . $urlGenerator->generate('billing_invoice_webhook_mp')
            );
        } catch (\Throwable $e) {
            // El instituto no puede hacer nada con el detalle técnico, pero sí necesita saber
            // que no es culpa suya y que tiene otra forma de pagar.
            $this->addFlash('danger', 'No pudimos generar el link de Mercado Pago. Probá de nuevo en un rato o pagá por transferencia.');

            return $this->redirectToRoute('billing_invoice_index');
        }

        return $this->redirect($link);
    }
}
