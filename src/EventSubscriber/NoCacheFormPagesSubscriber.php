<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Evita que el navegador cachee páginas con formularios (edit/new).
 * Si se sirviera una página desde caché tras un nuevo login, el token CSRF
 * sería de la sesión anterior y el envío fallaría con "token CSRF no válido".
 */
class NoCacheFormPagesSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Solo aplicar a respuestas HTML exitosas
        if ($response->getStatusCode() !== 200) {
            return;
        }
        if (!str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            return;
        }

        $route = $request->attributes->get('_route', '');
        if ($route === '') {
            return;
        }

        // Rutas que muestran formularios con CSRF (edit/new)
        $formRoutes = [
            'app_profesor_edit',
            'app_profesor_new',
            'app_alumno_edit',
            'app_alumno_new',
            'app_curso_edit',
            'app_curso_new',
        ];

        if (!in_array($route, $formRoutes, true)) {
            return;
        }

        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }
}
