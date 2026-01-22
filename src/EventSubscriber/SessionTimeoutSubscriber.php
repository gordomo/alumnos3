<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class SessionTimeoutSubscriber implements EventSubscriberInterface
{
    private const TIMEOUT_SECONDS = 600; // 10 minutos
    private const LAST_ACTIVITY_KEY = '_last_activity';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private SessionInterface $session,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9], // Prioridad 9 para ejecutarse antes del firewall
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Solo procesar requests principales (no sub-requests)
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        
        // Excluir rutas públicas y rutas de assets
        $path = $request->getPathInfo();
        $publicPaths = [
            '/login',
            '/logout',
            '/register',
            '/reset-password',
            '/',
        ];
        
        // Si es una ruta pública o un asset, no hacer nada
        if (in_array($path, $publicPaths) || 
            str_starts_with($path, '/assets/') || 
            str_starts_with($path, '/css/') || 
            str_starts_with($path, '/js/') || 
            str_starts_with($path, '/images/') ||
            str_starts_with($path, '/_')) {
            return;
        }

        // Verificar si el usuario está autenticado
        $token = $this->tokenStorage->getToken();
        if (!$token || !$token->getUser()) {
            return;
        }

        // Obtener la última actividad de la sesión
        $lastActivity = $this->session->get(self::LAST_ACTIVITY_KEY);
        $currentTime = time();

        // Si no hay registro de última actividad, establecerlo ahora
        if ($lastActivity === null) {
            $this->session->set(self::LAST_ACTIVITY_KEY, $currentTime);
            return;
        }

        // Calcular el tiempo transcurrido desde la última actividad
        $timeElapsed = $currentTime - $lastActivity;

        // Si han pasado más de 10 minutos sin actividad, cerrar sesión
        if ($timeElapsed > self::TIMEOUT_SECONDS) {
            // Invalidar la sesión
            $this->session->invalidate();
            
            // Limpiar el token de autenticación
            $this->tokenStorage->setToken(null);
            
            // Redirigir al login con mensaje
            $this->session->getFlashBag()->add('warning', 'Su sesión ha expirado por inactividad. Por favor, inicie sesión nuevamente.');
            
            $response = new RedirectResponse($this->urlGenerator->generate('app_login'));
            $event->setResponse($response);
            return;
        }

        // Actualizar la última actividad
        $this->session->set(self::LAST_ACTIVITY_KEY, $currentTime);
    }
}
