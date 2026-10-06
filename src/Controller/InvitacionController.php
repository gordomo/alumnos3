<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\LoginFormAuthAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

/**
 * El segundo paso del alta: la persona elige su contraseña y entra.
 *
 * Es público a propósito, igual que el enlace de familia: no hay sesión todavía. Lo que autoriza
 * es el token de la URL, que se valida contra un usuario y una fecha de vencimiento.
 *
 * La contraseña la elige quien recibe el mail; nosotros nunca la conocemos ni viaja escrita.
 */
class InvitacionController extends AbstractController
{
    /**
     * @Route("/invitacion/{token}", name="app_invitacion", methods={"GET", "POST"},
     *     requirements={"token"="[A-Za-z0-9]{32,128}"})
     */
    public function completar(
        string $token,
        Request $request,
        UserRepository $usuarios,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthAuthenticator $loginAuthenticator
    ): Response {
        $usuario = $usuarios->findOneBy(['resetToken' => $token]);

        $vencida = !$usuario
            || !$usuario->getResetTokenExpiresAt()
            || $usuario->getResetTokenExpiresAt() < new \DateTime();

        if ($vencida) {
            return $this->render('security/invitacion_vencida.html.twig');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('invitacion', (string) $request->request->get('_token'))) {
                $this->addFlash('invitacion_error', 'Se venció el formulario. Volvé a intentar.');

                return $this->redirectToRoute('app_invitacion', ['token' => $token]);
            }

            $password = (string) $request->request->get('password');
            $repetida = (string) $request->request->get('password_repetida');

            if (strlen($password) < 8) {
                $this->addFlash('invitacion_error', 'La contraseña tiene que tener al menos 8 caracteres.');

                return $this->redirectToRoute('app_invitacion', ['token' => $token]);
            }

            if ($password !== $repetida) {
                $this->addFlash('invitacion_error', 'Las dos contraseñas no coinciden.');

                return $this->redirectToRoute('app_invitacion', ['token' => $token]);
            }

            $usuario->setPassword($passwordHasher->hashPassword($usuario, $password));

            // El token se quema acá: el enlace es de un solo uso.
            $usuario->setResetToken(null);
            $usuario->setResetTokenExpiresAt(null);
            $em->flush();

            $this->addFlash('success', '¡Listo! Tu instituto ya está activo. Empezá cargando tus cursos.');

            return $userAuthenticator->authenticateUser($usuario, $loginAuthenticator, $request);
        }

        return $this->render('security/invitacion.html.twig', [
            'usuario' => $usuario,
            'token' => $token,
        ]);
    }
}
