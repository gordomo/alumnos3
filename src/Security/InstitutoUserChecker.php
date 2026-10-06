<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Impide entrar a cualquier usuario de un instituto dado de baja.
 *
 * Se hace acá y no en cada controller porque alcanza con un solo lugar: si el chequeo estuviera
 * repartido, bastaría una pantalla olvidada para que el instituto siguiera operando.
 *
 * Alcanza a todos sus usuarios -administración, profesores y alumnos-, porque la baja es del
 * instituto entero. Los usuarios sin instituto (super admin, admin de la plataforma) no se tocan.
 */
class InstitutoUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        $instituto = $user->getInstituto();

        if ($instituto && !$instituto->isActivo()) {
            throw new CustomUserMessageAccountStatusException(
                'Este instituto está dado de baja. Si creés que es un error, escribinos.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Nada que revisar después de autenticar.
    }
}
