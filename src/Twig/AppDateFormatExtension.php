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
        private ?\Symfony\Bundle\SecurityBundle\Security $security = null
    ) {
    }

    public function getGlobals(): array
    {
        $format = 'd/m/Y';
        $timezone = date_default_timezone_get();
        
        $user = $this->security?->getUser();
        if ($user !== null && method_exists($user, 'getInstituto')) {
            $instituto = $user->getInstituto();
            if ($instituto !== null) {
                $format = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
                $timezone = $this->institutoTimezoneService->getTimezoneForInstituto($instituto);
            }
        }
        return [
            'app_date_format' => $format,
            'app_timezone' => $timezone,
        ];
    }
}
