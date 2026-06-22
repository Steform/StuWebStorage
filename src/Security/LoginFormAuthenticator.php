<?php

namespace App\Security;

use App\Entity\User;
use App\Controller\SecurityUiController;
use App\Repository\UserRepository;
use App\Service\Site\SiteAccessGateService;
use App\Service\Auth\TrustedDeviceService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Class LoginFormAuthenticator.
 */
class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login_check';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TrustedDeviceService $trustedDeviceService,
        private readonly SecurityUiController $securityUiController,
        private readonly UserRepository $userRepository,
        private readonly SiteAccessGateService $siteAccessGateService,
    ) {
    }

    /**
     * @brief Authenticate user from login form payload.
     * @param Request $request Current request.
     * @return Passport
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function authenticate(Request $request): Passport
    {
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        $request->getSession()->set('_security.last_username', $email);

        return new Passport(
            new UserBadge($email, function (string $userIdentifier): User {
                $user = $this->userRepository->findOneBy(['email' => $userIdentifier]);
                if (!$user instanceof User || !$user->isActive()) {
                    throw new CustomUserMessageAuthenticationException('admin.users.error.account_inactive');
                }

                if (
                    $this->siteAccessGateService->isMaintenanceModeEnabled()
                    && !in_array('ROLE_ADMIN', $user->getRoles(), true)
                ) {
                    throw new CustomUserMessageAuthenticationException('maintenance.error.login_forbidden');
                }

                return $user;
            }),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
        );
    }

    /**
     * @brief Handle successful authentication.
     * @param Request $request Current request.
     * @param TokenInterface $token Authenticated token.
     * @param string $firewallName Firewall name.
     * @return Response|null
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return new RedirectResponse($this->urlGenerator->generate('app_home'));
        }

        if ($user->isTotpEnabled() && !$this->trustedDeviceService->isTrustedDevice((int) $user->getId(), $request)) {
            $rememberRequested = (string) $request->request->get('_remember_me', '') !== '';
            $this->securityUiController->startTotpStep($request, $user, $rememberRequested);

            return new RedirectResponse('/login/totp');
        }

        if ($request->hasSession()) {
            $request->getSession()->set('auth.session_version', $user->getSessionVersion());
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    /**
     * @brief Return login URL.
     * @param Request $request Current request.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    /**
     * @brief Log authentication failure context then delegate default behavior.
     * @param Request $request Current request.
     * @param AuthenticationException $exception Authentication failure exception.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $response = parent::onAuthenticationFailure($request, $exception);
        if ($response instanceof RedirectResponse && $response->getTargetUrl() === '/login/check') {
            return new RedirectResponse('/login');
        }

        return $response;
    }
}
