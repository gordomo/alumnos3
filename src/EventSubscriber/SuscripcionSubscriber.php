<?php

namespace App\EventSubscriber;

use App\Service\SuscripcionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Security;

/**
 * Aplica el límite por suscripción impaga, en un solo lugar.
 *
 * Se hace acá y no en cada controller por dos razones: son más de cuarenta pantallas de
 * administración, y si el chequeo estuviera repartido alcanzaría con olvidarlo en una para que el
 * bloqueo no sirva.
 *
 * La escalera es la que decidió el instituto:
 *
 * - Solo lectura: puede mirar y exportar todo, y sobre todo puede SEGUIR COBRÁNDOLES A SUS
 *   ALUMN@S, que es la plata con la que nos va a pagar. No puede dar de alta ni comunicar.
 * - Bloqueada: el panel de administración lo lleva a la pantalla de suscripción.
 *
 * Lo que nunca se toca: los profesores, los alumn@s y las familias. No son los que deben, y
 * dejarlos afuera perjudica al instituto frente a sus propios clientes.
 */
class SuscripcionSubscriber implements EventSubscriberInterface
{
    /**
     * Rutas que siguen funcionando siempre, incluso con el panel bloqueado: la propia pantalla
     * de suscripción, salir del sistema y la ayuda.
     */
    private const SIEMPRE_PERMITIDAS = [
        'billing_invoice_',
        'app_logout',
        'app_login',
        'app_start',
        'app_ayuda',
        'app_familia',
        'app_reset_password',
    ];

    /**
     * Lo que se sigue pudiendo escribir en modo solo lectura: registrar y editar los pagos de
     * los alumn@s. Cortarle la cobranza sería quitarle la fuente de ingresos con la que paga.
     */
    private const ESCRITURA_PERMITIDA = [
        'app_alumnos_pagos_',
        'billing_invoice_',
    ];

    /** @var array<int, array> Memoria por request: el estado se calcula una sola vez. */
    private array $estados = [];

    public function __construct(
        private Security $security,
        private SuscripcionService $suscripcionService,
        private UrlGeneratorInterface $urlGenerator,
        private AuthorizationCheckerInterface $authChecker
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Después del firewall, para que ya haya usuario en sesión.
        return [KernelEvents::REQUEST => ['alPedido', 6]];
    }

    public function alPedido(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $usuario = $this->security->getUser();
        if (!$usuario || !method_exists($usuario, 'getInstituto') || !$usuario->getInstituto()) {
            return;
        }

        // Solo el administrador del instituto: es quien nos debe y quien puede pagar.
        if (!$this->authChecker->isGranted('ROLE_ADMIN_INSTITUTO')) {
            return;
        }

        $request = $event->getRequest();
        $ruta = (string) $request->attributes->get('_route');

        if ($ruta === '' || $this->empiezaCon($ruta, self::SIEMPRE_PERMITIDAS)) {
            return;
        }

        $instituto = $usuario->getInstituto();
        $clave = $instituto->getId();

        if (!isset($this->estados[$clave])) {
            $this->estados[$clave] = $this->suscripcionService->estado($instituto);
        }

        $estado = $this->estados[$clave];

        if ($estado['bloqueado']) {
            $this->mandarASuscripcion($event, 'Tu suscripción está impaga, así que el panel quedó bloqueado. Regularizala para volver a usar el sistema.');

            return;
        }

        // Solo lectura: se cortan las escrituras, no la navegación.
        if (!$estado['escribe'] && !$request->isMethodSafe() && !$this->empiezaCon($ruta, self::ESCRITURA_PERMITIDA)) {
            $this->mandarASuscripcion($event, 'Con la suscripción vencida el sistema queda en modo consulta: podés ver todo y seguir registrando los pagos de tus alumn@s, pero no dar de alta ni enviar comunicaciones.');
        }
    }

    /**
     * @param string[] $prefijos
     */
    private function empiezaCon(string $ruta, array $prefijos): bool
    {
        foreach ($prefijos as $prefijo) {
            if (str_starts_with($ruta, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    private function mandarASuscripcion(RequestEvent $event, string $mensaje): void
    {
        $sesion = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
        if ($sesion && method_exists($sesion, 'getFlashBag')) {
            $sesion->getFlashBag()->add('warning', $mensaje);
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('billing_invoice_index')
        ));
    }
}
