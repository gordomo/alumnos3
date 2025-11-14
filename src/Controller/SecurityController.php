<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SecurityController extends AbstractController
{
    private $urlGenerator;
    public function __construct(UrlGeneratorInterface $urlGenerator)
    {
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * @Route("/", name="app_start")
     */
    public function start(): Response
    {
        $user = $this->getUser();
        if ($user) {
            // Redirigir según el rol (SUPER_ADMIN tiene prioridad)
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles())) {
                return new RedirectResponse($this->urlGenerator->generate('admin_instituto_index'));
            }
            
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                return new RedirectResponse($this->urlGenerator->generate('admin_instituto_index'));
            }
            
            if (in_array('ROLE_ADMIN_INSTITUTO', $user->getRoles())) {
                return new RedirectResponse($this->urlGenerator->generate('dashboard_index'));
            }
            
            if (in_array('ROLE_PROFESOR', $user->getRoles())) {
                return new RedirectResponse($this->urlGenerator->generate('app_profesor_dashboard'));
            }
        }
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    /**
     * @Route("/login", name="app_login")
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // if ($this->getUser()) {
        //     return $this->redirectToRoute('target_path');
        // }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    /**
     * @Route("/logout", name="app_logout")
     */
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
