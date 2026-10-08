<?php

namespace App\Twig;

use App\Service\InstitutoTimezoneService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\Extension\GlobalsInterface;

/**
 * Expone el formato de fecha del instituto del usuario actual para usar en toda la app.
 * Si no hay usuario/instituto (admin, público), se usa d/m/Y.
 */
class AppDateFormatExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private InstitutoTimezoneService $institutoTimezoneService,
        // Security vive en Security\Core en Symfony 5.4; el de SecurityBundle no existe todavía.
        // Con el otro tipo, el parámetro quedaba siempre en null y estos globals nunca tomaban
        // el formato ni la zona horaria del instituto.
        private ?\Symfony\Component\Security\Core\Security $security = null
    ) {
    }

    public function getGlobals(): array
    {
        $format = 'd/m/Y';
        $timezone = date_default_timezone_get();

        // Symfony evalúa los globales al calentar la caché de Twig, y eso pasa también cuando se
        // construye la imagen de Docker, donde todavía no hay base de datos. Sin este try la
        // consulta explota y el build entero falla con un "getaddrinfo for db failed", que no se
        // parece en nada al problema real.
        //
        // Más allá del build: un formato de fecha es decoración. Si la base no responde, lo
        // correcto es mostrar el formato por defecto, no tirar la página abajo.
        try {
            $user = $this->security?->getUser();
            if ($user !== null && method_exists($user, 'getInstituto')) {
                $instituto = $user->getInstituto();
                if ($instituto !== null) {
                    $format = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
                    $timezone = $this->institutoTimezoneService->getTimezoneForInstituto($instituto);
                }
            }
        } catch (\Throwable $e) {
            // Se siguen usando los valores por defecto de arriba.
        }

        return [
            'app_date_format' => $format,
            'app_timezone' => $timezone,
        ];
    }
}
