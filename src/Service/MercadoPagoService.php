<?php

namespace App\Service;

use App\Entity\BillingInvoice;
use App\Repository\BillingConfigRepository;

/**
 * Cobro de la suscripción por Mercado Pago (Checkout Pro).
 *
 * El circuito es: se crea una "preference" con lo que hay que cobrar y Mercado Pago devuelve un
 * link; el instituto paga ahí y después MP nos avisa por webhook que el pago se acreditó. El
 * dinero va a nuestra cuenta: acá no hay terceros ni datos de tarjeta pasando por el sistema.
 *
 * Se usa curl y no un cliente HTTP: son dos llamadas a una API REST simple, y no valía la pena
 * sumar una dependencia nueva a un despliegue que ya es delicado.
 *
 * La factura se identifica con external_reference. Es lo único que viaja de ida y vuelta, así
 * que cuando el webhook avise de un pago vamos a saber a qué factura corresponde sin guardar
 * nada más.
 */
class MercadoPagoService
{
    private const API = 'https://api.mercadopago.com';

    public function __construct(private BillingConfigRepository $configRepository)
    {
    }

    public function estaConfigurado(): bool
    {
        return $this->configRepository->getOrCreatePriceConfig()->mercadoPagoConfigurado();
    }

    /**
     * Si las credenciales cargadas son de prueba. Con esto prendido, ningún pago es real.
     */
    public function enModoPrueba(): bool
    {
        return $this->configRepository->getOrCreatePriceConfig()->isMpModoPrueba();
    }

    /**
     * Cómo se identifican las facturas de un pago ante Mercado Pago, en los dos sentidos.
     *
     * Un pago puede cubrir varias facturas, así que la referencia lleva todos los ids. Es lo
     * único que viaja de ida y vuelta: cuando el webhook avise, de acá sale qué cancelar.
     *
     * @param BillingInvoice[] $facturas
     */
    public static function referencia(array $facturas): string
    {
        $ids = array_map(static fn(BillingInvoice $f) => $f->getId(), $facturas);

        return 'facturas-' . implode('-', $ids);
    }

    /**
     * @return int[]
     */
    public static function facturaIdsDeReferencia(?string $referencia): array
    {
        if (!$referencia) {
            return [];
        }

        // Se acepta "factura-6" además de "facturas-6-7": es la forma que tenían las referencias
        // antes de que un pago pudiera cubrir varias, y esos pagos siguen existiendo.
        if (!preg_match('/^facturas?-([\d-]+)$/', $referencia, $partes)) {
            return [];
        }

        return array_map('intval', array_filter(explode('-', $partes[1]), 'strlen'));
    }

    /**
     * Crea el link de pago de una factura y lo devuelve.
     *
     * @param string $volverA URL a la que vuelve el instituto después de pagar
     * @param string|null $webhook URL que Mercado Pago llama al acreditarse el pago
     *
     * @throws \RuntimeException si Mercado Pago rechaza la preferencia
     */
    public function crearLinkDePago(array $facturas, string $volverA, ?string $webhook = null): string
    {
        if (!$facturas) {
            throw new \RuntimeException('No hay facturas para cobrar.');
        }

        // Una línea por factura: en el checkout el instituto ve qué meses está pagando, y si
        // reclama algo después el detalle está del lado de Mercado Pago también.
        $items = [];
        foreach ($facturas as $factura) {
            $items[] = [
                'id' => 'factura-' . $factura->getId(),
                'title' => 'Suscripción Team Builder — ' . $factura->getFormattedPeriod(),
                'description' => sprintf(
                    '%d alumn@s activ@s en %s',
                    $factura->getActiveStudentsCount(),
                    $factura->getFormattedPeriod()
                ),
                'quantity' => 1,
                'currency_id' => 'ARS',
                'unit_price' => round((float) $factura->getTotalAmount(), 2),
            ];
        }

        $datos = [
            'items' => $items,
            'external_reference' => self::referencia($facturas),
            'statement_descriptor' => 'TEAMBUILDER',
        ];

        // Mercado Pago rechaza las URLs que no sean https. Mientras el sitio no tenga
        // certificado, se manda la preferencia sin ellas: el link funciona igual, lo que se
        // pierde es la vuelta automática y el aviso. Así el cobro no queda bloqueado esperando
        // al certificado, y cuando esté se empiezan a mandar solas.
        if (str_starts_with($volverA, 'https://')) {
            $datos['back_urls'] = [
                'success' => $volverA,
                'pending' => $volverA,
                'failure' => $volverA,
            ];
            $datos['auto_return'] = 'approved';
        }

        if ($webhook && str_starts_with($webhook, 'https://')) {
            $datos['notification_url'] = $webhook;
        }

        $respuesta = $this->llamar('POST', '/checkout/preferences', $datos);

        if (empty($respuesta['init_point'])) {
            throw new \RuntimeException(
                'Mercado Pago no devolvió un link de pago: ' . ($respuesta['message'] ?? 'respuesta inesperada')
            );
        }

        // En modo prueba hay dos links: el normal solo funciona con una cuenta real.
        return $this->enModoPrueba() && !empty($respuesta['sandbox_init_point'])
            ? $respuesta['sandbox_init_point']
            : $respuesta['init_point'];
    }

    /**
     * Trae un pago por su id, para saber si está aprobado y a qué factura corresponde.
     *
     * @return array<string, mixed>
     */
    public function buscarPago(string $pagoId): array
    {
        return $this->llamar('GET', '/v1/payments/' . rawurlencode($pagoId));
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     * @return array<string, mixed>
     */
    private function llamar(string $metodo, string $ruta, ?array $cuerpo = null): array
    {
        $token = $this->configRepository->getOrCreatePriceConfig()->getMpAccessToken();

        if (!$token) {
            throw new \RuntimeException('Mercado Pago no está configurado: falta el access token.');
        }

        $ch = curl_init(self::API . $ruta);
        $cabeceras = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $cabeceras,
        ]);

        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
        }

        $salida = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorRed = curl_error($ch);
        curl_close($ch);

        if ($salida === false) {
            throw new \RuntimeException('No se pudo contactar a Mercado Pago: ' . $errorRed);
        }

        $datos = json_decode((string) $salida, true);

        if (!is_array($datos)) {
            throw new \RuntimeException('Mercado Pago devolvió una respuesta que no se entiende (HTTP ' . $estado . ').');
        }

        if ($estado >= 400) {
            throw new \RuntimeException(sprintf(
                'Mercado Pago rechazó el pedido (HTTP %d): %s',
                $estado,
                $datos['message'] ?? $datos['error'] ?? 'sin detalle'
            ));
        }

        return $datos;
    }
}
