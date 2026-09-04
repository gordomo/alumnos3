<?php

namespace App\Twig;

use App\Service\SuscripcionService;
use Symfony\Component\Security\Core\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expone el estado de la suscripción a las plantillas, para el banner de aviso.
 *
 * Se lee del mismo servicio que aplica el bloqueo, así que el banner nunca puede decir una cosa
 * y el sistema hacer otra.
 */
class SuscripcionExtension extends AbstractExtension
{
    /** @var array|null|false false = todavía no se calculó en este request */
    private $cache = false;

    public function __construct(
        private Security $security,
        private SuscripcionService $suscripcionService
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('suscripcion', [$this, 'suscripcion']),
        ];
    }

    /**
     * El estado, o null si no corresponde mostrar nada (no hay admin de instituto logueado).
     */
    public function suscripcion(): ?array
    {
        if ($this->cache !== false) {
            return $this->cache;
        }

        $this->cache = null;

        $usuario = $this->security->getUser();
        if (!$usuario || !method_exists($usuario, 'getInstituto') || !$usuario->getInstituto()) {
            return null;
        }

        if (!$this->security->isGranted('ROLE_ADMIN_INSTITUTO')) {
            return null;
        }

        $estado = $this->suscripcionService->estado($usuario->getInstituto());

        // Solo se devuelve cuando hay algo que decir: al día no se muestra ningún banner.
        $this->cache = in_array($estado['estado'], [
            SuscripcionService::AL_DIA,
            SuscripcionService::EXENTA,
        ], true) ? null : $estado;

        return $this->cache;
    }
}
