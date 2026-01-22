<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Entity\InstitutoConfiguracion;
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
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
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

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        SluggerInterface $slugger,
        BillingService $billingService
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->slugger = $slugger;
        $this->billingService = $billingService;
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
        // Mostrar la landing page en lugar de redirigir
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        
        return $this->render('security/landing.html.twig', [
            'last_username' => $lastUsername, 
            'error' => $error,
            'is_home' => true
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

        return $this->render('security/landing.html.twig', [
            'last_username' => $lastUsername, 
            'error' => $error,
            'is_home' => false,
            'pricePerStudent' => $this->billingService->getPricePerStudentMonthly()
        ]);
    }

    /**
     * @Route("/register", name="app_register", methods={"POST"})
     */
    public function register(Request $request): Response
    {
        // Si ya está autenticado, redirigir
        if ($this->getUser()) {
            return $this->redirectToRoute('app_start');
        }

        if ($request->isMethod('POST')) {
            // Validar CSRF token
            $token = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('register', $token)) {
                $this->addFlash('error', 'Token de seguridad inválido.');
                return $this->redirectToRoute('app_login');
            }

            $nombre = trim($request->request->get('nombre', ''));
            $email = trim($request->request->get('email', ''));
            $tel = trim($request->request->get('tel', ''));
            $dir = trim($request->request->get('dir', ''));
            $password = $request->request->get('password', '');

            // Validaciones básicas
            if (empty($nombre) || empty($email) || empty($password)) {
                $this->addFlash('error', 'Por favor completa todos los campos obligatorios.');
                return $this->redirectToRoute('app_login');
            }

            if (strlen($password) < 6) {
                $this->addFlash('error', 'La contraseña debe tener al menos 6 caracteres.');
                return $this->redirectToRoute('app_login');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'El email ingresado no es válido.');
                return $this->redirectToRoute('app_login');
            }

            // Verificar si el email ya existe
            $institutoExistente = $this->entityManager->getRepository(Instituto::class)->findOneBy(['email' => $email]);
            $usuarioExistente = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($institutoExistente || $usuarioExistente) {
                $this->addFlash('error', 'El correo electrónico ya está en uso. Por favor, inicia sesión o usa otro email.');
                return $this->redirectToRoute('app_login');
            }

            try {
                // Crear el instituto
                $instituto = new Instituto();
                $instituto->setNombre($nombre);
                $instituto->setEmail($email);
                $instituto->setTel($tel ?: null);
                $instituto->setDir($dir ?: null);

                // Manejo del logo
                if ($request->files->has('logo')) {
                    $logoFile = $request->files->get('logo');
                    if ($logoFile && $logoFile->getSize() > 0) {
                        // Validar tamaño (máximo 1MB)
                        if ($logoFile->getSize() > 1024 * 1024) {
                            $this->addFlash('error', 'El logo es demasiado grande. Máximo 1MB.');
                            return $this->redirectToRoute('app_login');
                        }

                        $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                        $safeFilename = $this->slugger->slug($originalFilename);
                        $newFilename = $safeFilename . '-' . uniqid() . '.' . $logoFile->guessExtension();

                        try {
                            $logoFile->move(
                                $this->getParameter('logos_directory'),
                                $newFilename
                            );
                            $instituto->setLogo($newFilename);
                        } catch (FileException $e) {
                            // Si falla la subida del logo, continuar sin logo
                        }
                    }
                }

                // Crear usuario admin del instituto
                $user = new User();
                $user->setEmail($email);
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                $user->setRoles(['ROLE_ADMIN_INSTITUTO']);
                $user->setInstituto($instituto);

                // Crear configuración del instituto
                $configuracion = new InstitutoConfiguracion();
                $configuracion->setInstituto($instituto);
                
                // Persistir
                $this->entityManager->persist($instituto);
                $this->entityManager->persist($user);
                $this->entityManager->persist($configuracion);
                $this->entityManager->flush();

                $this->addFlash('success', '¡Cuenta creada exitosamente! Ya puedes iniciar sesión y comenzar a gestionar tu instituto.');
                
                // Redirigir al login
                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Ocurrió un error al crear la cuenta. Por favor, intenta nuevamente.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->redirectToRoute('app_login');
    }

    /**
     * @Route("/logout", name="app_logout")
     */
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
