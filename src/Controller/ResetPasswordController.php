<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;

/**
 * @Route("/reset-password")
 */
class ResetPasswordController extends AbstractController
{
    private $passwordHasher;
    private $emailService;
    private $limiter;

    public function __construct(
        UserPasswordHasherInterface $passwordHasher,
        EmailService $emailService,
        RateLimiterFactory $resetPasswordLimiter
    ) {
        $this->passwordHasher = $passwordHasher;
        $this->emailService = $emailService;
        $this->limiter = $resetPasswordLimiter;
    }

    /**
     * @Route("", name="app_reset_password")
     */
    public function request(Request $request, UserRepository $userRepository, TokenGeneratorInterface $tokenGenerator): Response
    {
        try {
            $limiter = $this->limiter->create($request->getClientIp());
            $limiter->consume(1);
        } catch (RateLimitExceededException $e) {
            $this->addFlash('reset_password_error', 'Demasiados intentos. Por favor, espera unos minutos antes de intentar de nuevo.');
            return $this->redirectToRoute('app_reset_password');
        }

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $user = $userRepository->findOneBy(['email' => $email]);

            if ($user) {
                // Verificar si ya tiene un token activo y no ha expirado
                $hasValidToken = $user->getResetToken() && 
                               $user->getResetTokenExpiresAt() && 
                               $user->getResetTokenExpiresAt() > new \DateTime();

                if ($hasValidToken) {
                    $now = new \DateTime();
                    $expiresAt = $user->getResetTokenExpiresAt();
                    $interval = $now->diff($expiresAt);
                    
                    $timeLeft = '';
                    if ($interval->h > 0) {
                        $timeLeft = $interval->h . ' hora' . ($interval->h > 1 ? 's' : '') . ' y ';
                    }
                    $timeLeft .= $interval->i . ' minuto' . ($interval->i != 1 ? 's' : '');
                    
                    $this->addFlash('reset_password_error', 'Ya se ha enviado un correo de recuperación. Por favor, revisa tu bandeja de entrada. El enlace expirará en ' . $timeLeft . '.');
                    
                    // NO pasar el token al template por seguridad
                    // Solo indicar que hay un token válido para mostrar el botón de reenvío
                    return $this->render('reset_password/request.html.twig', [
                        'has_valid_token' => true,
                        'email' => $email
                    ]);
                }

                // Si el token existe pero ha expirado, lo limpiamos
                if ($user->getResetToken()) {
                    $user->setResetToken(null);
                    $user->setResetTokenExpiresAt(null);
                    $user->setResetTokenSentAt(null);
                }

                $token = $tokenGenerator->generateToken();
                $user->setResetToken($token);
                $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));
                $user->setResetTokenSentAt(new \DateTime()); // Registrar cuándo se envió el email
                
                $userRepository->add($user);

                $this->emailService->sendPasswordResetEmail(
                    $user->getEmail(),
                    $token,
                    $user->getEmail()
                );

                $this->addFlash('success', 'Se ha enviado un correo con las instrucciones para recuperar tu contraseña.');
                return $this->redirectToRoute('app_login');
            }

            // No revelamos si el email existe o no para evitar enumeración de usuarios
            $this->addFlash('success', 'Si el correo electrónico existe en nuestro sistema, recibirás instrucciones para recuperar tu contraseña.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/request.html.twig', [
            'has_valid_token' => false
        ]);
    }

    /**
     * @Route("/resend", name="app_reset_password_resend", methods={"POST"})
     */
    public function resend(Request $request, UserRepository $userRepository, TokenGeneratorInterface $tokenGenerator): Response
    {
        try {
            $limiter = $this->limiter->create($request->getClientIp());
            $limiter->consume(1);
        } catch (RateLimitExceededException $e) {
            $this->addFlash('reset_password_error', 'Demasiados intentos. Por favor, espera unos minutos antes de intentar de nuevo.');
            return $this->redirectToRoute('app_reset_password');
        }

        // Obtener el email del formulario (requerido para seguridad)
        $email = $request->request->get('email');
        
        if (!$email) {
            $this->addFlash('reset_password_error', 'Debes proporcionar tu correo electrónico.');
            return $this->redirectToRoute('app_reset_password');
        }

        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            // No revelamos si el email existe o no para evitar enumeración de usuarios
            $this->addFlash('success', 'Si el correo electrónico existe en nuestro sistema, recibirás instrucciones para recuperar tu contraseña.');
            return $this->redirectToRoute('app_reset_password');
        }

        // Verificar que el usuario tenga un token válido
        $hasValidToken = $user->getResetToken() && 
                       $user->getResetTokenExpiresAt() && 
                       $user->getResetTokenExpiresAt() > new \DateTime();

        if (!$hasValidToken) {
            $this->addFlash('reset_password_error', 'No hay una solicitud de recuperación de contraseña pendiente. Por favor, solicita una nueva.');
            return $this->redirectToRoute('app_reset_password');
        }

        // Verificar límite de tiempo entre reenvíos (15 minutos)
        // En desarrollo, podemos permitir reenvíos más frecuentes para testing
        $minMinutesBetweenResends = $_ENV['APP_ENV'] === 'dev' ? 1 : 15;
        
        if ($user->getResetTokenSentAt()) {
            $lastSent = $user->getResetTokenSentAt();
            $now = new \DateTime();
            $minutesSinceLastSent = ($now->getTimestamp() - $lastSent->getTimestamp()) / 60;
            
            if ($minutesSinceLastSent < $minMinutesBetweenResends) {
                $remainingMinutes = ceil($minMinutesBetweenResends - $minutesSinceLastSent);
                $this->addFlash('reset_password_error', sprintf(
                    'Debes esperar %d minuto%s antes de poder reenviar el correo nuevamente.',
                    $remainingMinutes,
                    $remainingMinutes > 1 ? 's' : ''
                ));
                return $this->render('reset_password/request.html.twig', [
                    'has_valid_token' => true,
                    'email' => $email
                ]);
            }
        }

        // Generar un nuevo token y extender la expiración
        $newToken = $tokenGenerator->generateToken();
        $user->setResetToken($newToken);
        $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));
        $user->setResetTokenSentAt(new \DateTime()); // Registrar cuándo se envió el nuevo email
        
        $userRepository->add($user);

        try {
            $this->emailService->sendPasswordResetEmail(
                $user->getEmail(),
                $newToken,
                $user->getEmail()
            );

            $this->addFlash('success', 'Se ha reenviado el correo con las instrucciones para recuperar tu contraseña. Por favor, revisa tu bandeja de entrada.');
            
            // Redirigir mostrando el formulario de reenvío con el token válido
            return $this->render('reset_password/request.html.twig', [
                'has_valid_token' => true,
                'email' => $user->getEmail()
            ]);
        } catch (\Exception $e) {
            // Log del error para debugging
            error_log('Error al enviar correo de recuperación: ' . $e->getMessage());
            
            // Mensaje más específico si es un error de SSL
            $errorMessage = 'Error al enviar el correo. Por favor, intenta de nuevo más tarde.';
            if (strpos($e->getMessage(), 'certificate') !== false || strpos($e->getMessage(), 'SSL') !== false || strpos($e->getMessage(), 'TLS') !== false) {
                $errorMessage = 'Error de conexión con el servidor de correo. Por favor, contacta al administrador.';
            }
            
            $this->addFlash('reset_password_error', $errorMessage);
            
            // Si hay un token válido, mostrar el formulario de reenvío
            if ($hasValidToken) {
                return $this->render('reset_password/request.html.twig', [
                    'has_valid_token' => true,
                    'email' => $email
                ]);
            }
        }

        // IMPORTANTE: Redirigir de vuelta a la página de solicitud, NO a la pantalla de restablecimiento
        // El usuario DEBE hacer clic en el enlace del email para poder cambiar la contraseña
        return $this->redirectToRoute('app_reset_password');
    }

    /**
     * @Route("/{token}", name="app_reset_password_confirm")
     */
    public function reset(Request $request, string $token, UserRepository $userRepository): Response
    {
        try {
            $limiter = $this->limiter->create($request->getClientIp());
            $limiter->consume(1);
        } catch (RateLimitExceededException $e) {
            $this->addFlash('reset_password_error', 'Demasiados intentos. Por favor, espera unos minutos antes de intentar de nuevo.');
            return $this->redirectToRoute('app_reset_password');
        }

        $user = $userRepository->findOneBy(['resetToken' => $token]);

        if (!$user) {
            $this->addFlash('reset_password_error', 'El enlace de recuperación de contraseña no es válido.');
            return $this->redirectToRoute('app_reset_password');
        }

        if (!$user->getResetTokenExpiresAt() || $user->getResetTokenExpiresAt() < new \DateTime()) {
            $this->addFlash('reset_password_error', 'El enlace de recuperación de contraseña ha expirado.');
            return $this->redirectToRoute('app_reset_password');
        }

        // Pasar el usuario al template para mostrar información
        $templateData = [
            'token' => $token,
            'user' => $user
        ];

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');

            if (strlen($password) < 8) {
                $this->addFlash('reset_password_error', 'La contraseña debe tener al menos 8 caracteres.');
                return $this->render('reset_password/reset.html.twig', [
                    'token' => $token,
                    'user' => $user
                ]);
            }

            if ($password !== $confirmPassword) {
                $this->addFlash('reset_password_error', 'Las contraseñas no coinciden.');
                return $this->render('reset_password/reset.html.twig', [
                    'token' => $token,
                    'user' => $user
                ]);
            }

            // Verificar que el token no haya sido usado
            if ($user->getResetToken() !== $token) {
                $this->addFlash('reset_password_error', 'Este enlace ya ha sido utilizado.');
                return $this->redirectToRoute('app_reset_password');
            }

            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);
            
            $userRepository->add($user);

            $this->addFlash('success', 'Tu contraseña ha sido actualizada correctamente.');
            return $this->redirectToRoute('app_login');
        }

        // Si no es POST, mostrar el formulario de cambio de contraseña
        return $this->render('reset_password/reset.html.twig', $templateData);
    }
} 