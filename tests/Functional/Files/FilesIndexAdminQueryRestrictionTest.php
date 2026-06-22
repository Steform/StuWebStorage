<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Entity\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * @brief HTTP contract for /files admin query restriction and /admin/files non-regression.
 * @date 2026-05-06
 * @author Stephane H.
 */
final class FilesIndexAdminQueryRestrictionTest extends KernelTestCase
{
    /**
     * @brief Return one persisted user matching all required roles or null when unavailable.
     * @param UserRepository $userRepository User repository.
     * @param string[] $requiredRoles Required role names.
     * @return User|null
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function findUserWithRoles(UserRepository $userRepository, array $requiredRoles): ?User
    {
        foreach ($userRepository->findAll() as $candidate) {
            $roles = $candidate->getRoles();
            $ok = true;
            foreach ($requiredRoles as $requiredRole) {
                if (!\in_array($requiredRole, $roles, true)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @brief Perform one HTTP request with an authenticated persisted user.
     * @param HttpKernelInterface $kernel HTTP kernel.
     * @param EventDispatcherInterface $dispatcher Event dispatcher.
     * @param TokenStorageInterface $tokenStorage Token storage.
     * @param User $user Authenticated user.
     * @param string $uri Absolute path and query.
     * @return Response
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function requestAsUser(
        HttpKernelInterface $kernel,
        EventDispatcherInterface $dispatcher,
        TokenStorageInterface $tokenStorage,
        User $user,
        string $uri
    ): Response {
        $token = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        $dispatcher->addListener(KernelEvents::REQUEST, function () use ($token, $tokenStorage): void {
            $tokenStorage->setToken($token);
        }, 300);

        $request = Request::create($uri, 'GET');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        return $kernel->handle($request);
    }

    /**
     * @brief /files must redirect to canonical root when admin_context is present.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesRejectsAdminContextQueryParam(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $user = $this->findUserWithRoles($userRepository, ['ROLE_SHARE']);
        if (!$user instanceof User) {
            self::markTestSkipped('No persisted ROLE_SHARE user in database for /files restriction test.');
        }

        $response = $this->requestAsUser(
            $container->get(HttpKernelInterface::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(TokenStorageInterface::class),
            $user,
            '/files?admin_context=1'
        );

        Assert::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        Assert::assertStringContainsString('/files', $location);
        Assert::assertNull(parse_url($location, PHP_URL_QUERY));
    }

    /**
     * @brief /files must redirect to canonical root when admin_view_scope is present.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesRejectsAdminViewScopeQueryParam(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $user = $this->findUserWithRoles($userRepository, ['ROLE_SHARE']);
        if (!$user instanceof User) {
            self::markTestSkipped('No persisted ROLE_SHARE user in database for /files restriction test.');
        }

        $response = $this->requestAsUser(
            $container->get(HttpKernelInterface::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(TokenStorageInterface::class),
            $user,
            '/files?admin_view_scope=all'
        );

        Assert::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        Assert::assertStringContainsString('/files', $location);
        Assert::assertNull(parse_url($location, PHP_URL_QUERY));
    }

    /**
     * @brief /files must redirect to canonical root when view_scope=all is present.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesRejectsViewScopeAllQueryParam(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $user = $this->findUserWithRoles($userRepository, ['ROLE_SHARE']);
        if (!$user instanceof User) {
            self::markTestSkipped('No persisted ROLE_SHARE user in database for /files restriction test.');
        }

        $response = $this->requestAsUser(
            $container->get(HttpKernelInterface::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(TokenStorageInterface::class),
            $user,
            '/files?view_scope=all'
        );

        Assert::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        Assert::assertStringContainsString('/files', $location);
        Assert::assertNull(parse_url($location, PHP_URL_QUERY));
    }

    /**
     * @brief /files must redirect to canonical root for combined forbidden params.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesRejectsCombinedForbiddenQueryParams(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $user = $this->findUserWithRoles($userRepository, ['ROLE_SHARE']);
        if (!$user instanceof User) {
            self::markTestSkipped('No persisted ROLE_SHARE user in database for /files restriction test.');
        }

        $response = $this->requestAsUser(
            $container->get(HttpKernelInterface::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(TokenStorageInterface::class),
            $user,
            '/files?admin_context=1&admin_view_scope=all&view_scope=all'
        );

        Assert::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        Assert::assertStringContainsString('/files', $location);
        Assert::assertNull(parse_url($location, PHP_URL_QUERY));
    }

    /**
     * @brief Bare /files for share-send users must not be redirected by admin query restriction.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesWithoutForbiddenParamsDoesNotRedirectToCanonicalRoot(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $user = $this->findUserWithRoles($userRepository, ['ROLE_SHARE', 'ROLE_SHARE_SEND']);
        if (!$user instanceof User) {
            self::markTestSkipped('No persisted ROLE_SHARE + ROLE_SHARE_SEND user in database for non-redirect /files test.');
        }

        $response = $this->requestAsUser(
            $container->get(HttpKernelInterface::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(TokenStorageInterface::class),
            $user,
            '/files'
        );

        if ($response->getStatusCode() === 302 && str_contains((string) $response->headers->get('Location'), '/login')) {
            self::markTestSkipped('Kernel HTTP stack did not keep programmatic token for this request.');
        }

        $location = (string) $response->headers->get('Location');
        Assert::assertFalse($response->isRedirect(), 'Bare /files must not be redirected by admin query restriction.');
        Assert::assertSame('', $location);
    }

    /**
     * @brief /admin/files behavior must remain unchanged by /files query restriction.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testAdminFilesRouteStillRedirectsToCanonicalAdminScope(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $adminUser = $this->findUserWithRoles($userRepository, ['ROLE_ADMIN']);
        if (!$adminUser instanceof User) {
            self::markTestSkipped('No persisted ROLE_ADMIN user in database for admin route non-regression test.');
        }

        $response = $this->requestAsUser(
            $container->get(HttpKernelInterface::class),
            $container->get(EventDispatcherInterface::class),
            $container->get(TokenStorageInterface::class),
            $adminUser,
            '/admin/files'
        );

        if ($response->getStatusCode() === 302 && str_contains((string) $response->headers->get('Location'), '/login')) {
            self::markTestSkipped('Kernel HTTP stack did not keep programmatic admin token for this request.');
        }

        Assert::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        Assert::assertStringContainsString('/admin/files', $location);
        Assert::assertStringContainsString('admin_view_scope=', $location);
    }
}

