<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Entity\InstitutoConfiguracion;
use App\Entity\MetodoPago;
use App\Entity\User;
use App\Repository\InstitutoConfiguracionRepository;
use App\Repository\InstitutoRepository;
use App\Repository\UserRepository;
use App\Service\BillingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use App\Security\LoginFormAuthAuthenticator;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class SecurityController extends AbstractController
{
    private $urlGenerator;
    private $entityManager;
    private $passwordHasher;
    private $slugger;
    private $billingService;
    private $userAuthenticator;
    private $loginAuthenticator;

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        SluggerInterface $slugger,
        BillingService $billingService,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthAuthenticator $loginAuthenticator
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->slugger = $slugger;
        $this->billingService = $billingService;
        $this->userAuthenticator = $userAuthenticator;
        $this->loginAuthenticator = $loginAuthenticator;
    }

    /**
     * @Route("/", name="app_start")
     */
    public function start(AuthenticationUtils $authenticationUtils): Response
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
            
            if (in_array('ROLE_ALUMNO', $user->getRoles())) {
                return new RedirectResponse($this->urlGenerator->generate('app_alumno_dashboard'));
            }
        }
        
        // Mostrar la landing page con las variables necesarias
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        
        return $this->render('security/landing.html.twig', [
            'last_username' => $lastUsername, 
            'error' => $error,
            'is_home' => true,
            'pricePerStudent' => $this->billingService->getPricePerStudentMonthly()
        ]);
    }

    /**
     * @Route("/login", name="app_login")
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Si ya está autenticado, redirigir
        if ($this->getUser()) {
            return $this->redirectToRoute('app_start');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername, 
            'error' => $error
        ]);
    }

    /*
     * El registro automático se eliminó a propósito.
     *
     * Hasta acá, cualquiera con el enlace creaba un instituto completo -con su usuario
     * administrador y su configuración- sin que nadie lo revisara. Ahora el alta pasa por una
     * solicitud que alguien confirma (ContactoController y SolicitudInstitutoController), y el
     * instituto nace recién en ese momento.
     *
     * La ruta se borra, no se esconde: con el formulario fuera de la landing pero la ruta viva,
     * se podía seguir posteando igual.
     */

    /**
     * @Route("/logout", name="app_logout")
     */
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
