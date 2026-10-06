<?php

namespace App\Twig;

use App\Repository\SolicitudInstitutoRepository;
use Symfony\Component\Security\Core\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Cuántas solicitudes de alta están esperando, para el contador del menú.
 *
 * Sin esto hay que acordarse de entrar a mirar, y una solicitud sin contestar es un cliente que
 * se va a otro lado.
 */
class SolicitudesExtension extends AbstractExtension
{
    /** @var int|null|false false = todavía no se consultó en este request */
    private $cache = false;

    public function __construct(
        private Security $security,
        private SolicitudInstitutoRepository $solicitudes
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('solicitudes_pendientes', [$this, 'pendientes']),
        ];
    }

    public function pendientes(): int
    {
        if ($this->cache !== false) {
            return $this->cache;
        }

        if (!$this->security->isGranted('ROLE_ADMIN') && !$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->cache = 0;
        }

        return $this->cache = $this->solicitudes->contarPendientes();
    }
}
