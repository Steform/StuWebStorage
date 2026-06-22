<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\AdminGodviewSessionStateService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * @brief Contract and HTTP checks for bare /admin/files session memory and fallback all-users redirect.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class AdminGodviewDefaultScopeContractTest extends KernelTestCase
{
    /**
     * @brief Read repository file contents as a raw string.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief FilesController must wire session godview redirect and persistence helpers.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testControllerDeclaresGodviewSessionMemoryFlow(): void
    {
        $src = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString(AdminGodviewSessionStateService::class, $src);
        self::assertStringContainsString('maybeRedirectAdminGodviewFromSessionMemory', $src);
        self::assertStringContainsString('persistAdminGodviewStateFromViewData', $src);
        self::assertStringContainsString('isAdminGodviewViewScopeQueryOmitted', $src);
    }

    /**
     * @brief Service file must exist with session key and validation API.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminGodviewSessionStateServiceFileExists(): void
    {
        $src = $this->readSource('src/Service/Admin/AdminGodviewSessionStateService.php');

        self::assertStringContainsString('admin_godview.last_state', $src);
        self::assertStringContainsString('function isValidRememberedState', $src);
        self::assertStringContainsString('function buildRedirectQueryFromState', $src);
    }

    /**
     * @brief Skip DB when unreachable; bare /admin/files with empty session must 302 to all-users scope.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testBareAdminFilesRedirectsToAllUsersWhenNoSessionMemory(): void
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
        $adminUser = null;
        foreach ($userRepository->findAll() as $candidate) {
            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                $adminUser = $candidate;
                break;
            }
        }
        if (!$adminUser instanceof User) {
            self::markTestSkipped('No persisted ROLE_ADMIN user in database for godview HTTP redirect test.');
        }

        $token = new PostAuthenticationToken($adminUser, 'main', $adminUser->getRoles());
        $container->get(TokenStorageInterface::class)->setToken($token);

        $kernel = $container->get(HttpKernelInterface::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        $dispatcher->addListener(KernelEvents::REQUEST, function () use ($token, $container): void {
            $container->get(TokenStorageInterface::class)->setToken($token);
        }, 300);

        $request = Request::create('/admin/files', 'GET');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        $response = $kernel->handle($request);

        if ($response->getStatusCode() === 302 && str_contains((string) $response->headers->get('Location'), '/login')) {
            self::markTestSkipped('Kernel HTTP stack did not keep programmatic admin token for this request.');
        }

        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringContainsString('admin_view_scope=all', $location);
        self::assertStringContainsString('view_scope=all', $location);
    }

    /**
     * @brief Bare /admin/files must follow remembered owner drilldown when session contains valid state.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testBareAdminFilesRedirectsToRememberedSubjectWhenSessionPrimed(): void
    {
        try {
            self::bootKernel();
            $connection = static::getContainer()->get('doctrine.dbal.default_connection');
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $container = static::getContainer();
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);
        $adminUser = null;
        foreach ($userRepository->findAll() as $candidate) {
            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                $adminUser = $candidate;
                break;
            }
        }
        if (!$adminUser instanceof User) {
            self::markTestSkipped('No persisted ROLE_ADMIN user in database for godview memory redirect test.');
        }
        $subjectId = (int) $adminUser->getId();
        if ($subjectId < 1) {
            self::markTestSkipped('Admin user has no persisted id.');
        }

        $token = new PostAuthenticationToken($adminUser, 'main', $adminUser->getRoles());
        $container->get(TokenStorageInterface::class)->setToken($token);

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        /** @var AdminGodviewSessionStateService $godviewState */
        $godviewState = $container->get(AdminGodviewSessionStateService::class);
        $godviewState->rememberState($session, 'owner', 'user', $subjectId, $subjectId, null);

        $kernel = $container->get(HttpKernelInterface::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        $dispatcher->addListener(KernelEvents::REQUEST, function () use ($token, $container): void {
            $container->get(TokenStorageInterface::class)->setToken($token);
        }, 300);

        $request = Request::create('/admin/files', 'GET');
        $request->setSession($session);

        $response = $kernel->handle($request);

        if ($response->getStatusCode() === 302 && str_contains((string) $response->headers->get('Location'), '/login')) {
            self::markTestSkipped('Kernel HTTP stack did not keep programmatic admin token for this request.');
        }

        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringContainsString('admin_view_scope=owner', $location);
        self::assertStringContainsString('view_scope=user', $location);
        self::assertStringContainsString('subject_user='.$subjectId, $location);
    }

    /**
     * @brief partial=1 must not trigger session memory redirect (fragment fetch).
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testPartialAdminFilesDoesNotRedirectForOmittedAdminViewScope(): void
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
        $adminUser = null;
        foreach ($userRepository->findAll() as $candidate) {
            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                $adminUser = $candidate;
                break;
            }
        }
        if (!$adminUser instanceof User) {
            self::markTestSkipped('No persisted ROLE_ADMIN user in database for godview partial test.');
        }

        $token = new PostAuthenticationToken($adminUser, 'main', $adminUser->getRoles());
        $container->get(TokenStorageInterface::class)->setToken($token);

        $kernel = $container->get(HttpKernelInterface::class);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        $dispatcher->addListener(KernelEvents::REQUEST, function () use ($token, $container): void {
            $container->get(TokenStorageInterface::class)->setToken($token);
        }, 300);

        $request = Request::create('/admin/files', 'GET', ['partial' => '1']);
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        $response = $kernel->handle($request);

        if ($response->getStatusCode() === 302 && str_contains((string) $response->headers->get('Location'), '/login')) {
            self::markTestSkipped('Kernel HTTP stack did not keep programmatic admin token for this request.');
        }

        self::assertNotSame(302, $response->getStatusCode(), 'partial=1 must not 302 for bare admin_view_scope omission');
    }
}
