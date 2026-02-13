<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\LogoutException;

/**
 * Maneja el error de CSRF inválido al intentar cerrar sesión cuando la sesión ya expiró.
 * En lugar de mostrar una pantalla de error, redirige al login.
 */
class LogoutExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $throwable = $event->getThrowable();

        // Solo procesar si es una petición de logout
        $path = $request->getPathInfo();
        if ($path !== '/logout' && substr($path, -7) !== '/logout') {
            return;
        }

        // Verificar si es LogoutException (CSRF inválido) o AccessDeniedHttpException
        // que la envuelve (con previo LogoutException)
        $isLogoutCsrfError = false;
        $current = $throwable;
        while ($current !== null) {
            if ($current instanceof LogoutException) {
                $isLogoutCsrfError = true;
                break;
            }
            if ($current instanceof AccessDeniedHttpException
                && false !== strpos($current->getMessage(), 'Invalid CSRF token')) {
                $isLogoutCsrfError = true;
                break;
            }
            $current = method_exists($current, 'getPrevious') ? $current->getPrevious() : null;
        }

        if (!$isLogoutCsrfError) {
            return;
        }

        // Redirigir al login ( mismo resultado que un logout exitoso)
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }
}
