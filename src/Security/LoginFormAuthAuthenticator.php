<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

class LoginFormAuthAuthenticator extends AbstractLoginFormAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    private UrlGeneratorInterface $urlGenerator;
    private FlashBagInterface $flashBag;

    public function __construct(UrlGeneratorInterface $urlGenerator, FlashBagInterface $flashBag)
    {
        $this->urlGenerator = $urlGenerator;
        $this->flashBag = $flashBag;
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');
        $request->getSession()->set(Security::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Intentar obtener la URL objetivo almacenada en la sesión
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }
        
        // Si no hay URL objetivo, redirigir según el rol
        $user = $token->getUser();
        if ($user instanceof User) {
            if ($user->hasRole('ROLE_ADMIN')) {
                return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
            }
            if ($user->hasRole('ROLE_ADMIN_INSTITUTO')) {
                return new RedirectResponse($this->urlGenerator->generate('instituto_dashboard'));
            }
            if ($user->hasRole('ROLE_PROFESOR')) {
                return new RedirectResponse($this->urlGenerator->generate('profesor_dashboard'));
            }
        }
        
        // Ruta por defecto
        return new RedirectResponse($this->urlGenerator->generate('dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->set(Security::AUTHENTICATION_ERROR, $exception);
        }

        $url = $this->getLoginUrl($request);

        return new RedirectResponse($url);
    }

    /**
     * Llamado cuando se necesita una autenticación (usuario no autenticado intentando acceder a ruta protegida)
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        // Guardar la URL que el usuario estaba intentando acceder
        $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
        
        // Agregar un mensaje flash informativo
        $this->flashBag->add('note', 'Debes iniciar sesión para acceder a esta página');
        
        return new RedirectResponse($this->urlGenerator->generate(self::LOGIN_ROUTE));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
