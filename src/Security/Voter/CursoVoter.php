<?php

namespace App\Security\Voter;

use App\Entity\Curso;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decide quién puede ver y cargar calificaciones de un curso.
 *
 * Existe porque la regla no es solo "mismo instituto" sino: mismo instituto Y (admin del
 * instituto O profesor asignado al curso) Y, para calificar, curso no cerrado. Aplica a
 * varias acciones en dos controllers y también a los templates, para ocultar botones con
 * is_granted('CURSO_CALIFICAR', curso).
 *
 * No reemplaza las reglas de access_control de security.yaml, que siguen siendo la primera
 * barrera por rol.
 */
class CursoVoter extends Voter
{
    public const CALIFICAR = 'CURSO_CALIFICAR';
    public const VER_NOTAS = 'CURSO_VER_NOTAS';

    protected function supports(string $attribute, $subject): bool
    {
        return in_array($attribute, [self::CALIFICAR, self::VER_NOTAS], true)
            && $subject instanceof Curso;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Curso $curso */
        $curso = $subject;

        $instituto = $user->getInstituto();
        if (!$instituto || !$curso->getInstituto()) {
            return false;
        }

        // Aislamiento entre institutos. No se delega en los repositorios: por ejemplo
        // CursoRepository::findByProfesor() no filtra por instituto.
        if ($curso->getInstituto()->getId() !== $instituto->getId()) {
            return false;
        }

        $roles = $user->getRoles();
        $esAdminInstituto = in_array('ROLE_ADMIN_INSTITUTO', $roles, true);
        $esProfesorDelCurso = $this->esProfesorDelCurso($user, $curso);

        if (!$esAdminInstituto && !$esProfesorDelCurso) {
            return false;
        }

        if ($attribute === self::VER_NOTAS) {
            return true;
        }

        // Calificar: un curso cerrado ya decidió aprobaciones, no se toca.
        return !$curso->getCerrado();
    }

    private function esProfesorDelCurso(User $user, Curso $curso): bool
    {
        $profesor = $user->getProfesor();
        if (!$profesor) {
            return false;
        }

        foreach ($curso->getProfesores() as $asignado) {
            if ($asignado->getId() === $profesor->getId()) {
                return true;
            }
        }

        return false;
    }
}
