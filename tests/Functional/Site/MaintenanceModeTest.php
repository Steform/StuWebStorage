<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Entity\User;
use App\Repository\SiteAccessGateSettingsRepository;
use App\Tests\Stub\TestUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @brief HTTP contract tests for platform maintenance mode.
 */
final class MaintenanceModeTest extends WebTestCase
{
    /**
     * @brief Reset in-memory test users between scenarios.
     *
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    protected function tearDown(): void
    {
        TestUserProvider::reset();
        parent::tearDown();
    }

    /**
     * @brief Anonymous visitors are blocked from the whole site except login during maintenance.
     *
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testMaintenanceBlocksAnonymousVisitorsOutsideLogin(): void
    {
        $client = static::createClient();
        $this->enableMaintenanceMode();

        try {
            $client->request('GET', '/files');
            self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('Maintenance in progress', (string) $client->getResponse()->getContent());

            $client->request('GET', '/login');
            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('Secure login', (string) $client->getResponse()->getContent());
        } finally {
            $this->disableMaintenanceMode();
        }
    }

    /**
     * @brief Logged-in non-admin users cannot use the platform during maintenance.
     *
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testMaintenanceBlocksLoggedInNonAdminUsers(): void
    {
        $user = $this->buildUser(['ROLE_USER', 'ROLE_SHARE'], 301);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $this->enableMaintenanceMode();

        try {
            $client->request('GET', '/files');
            self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('Maintenance in progress', (string) $client->getResponse()->getContent());
        } finally {
            $this->disableMaintenanceMode();
        }
    }

    /**
     * @brief Admin users keep full access while maintenance mode is active.
     *
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testMaintenanceAllowsAdminUsers(): void
    {
        $user = $this->buildUser(['ROLE_ADMIN'], 302);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $this->enableMaintenanceMode();

        try {
            $client->request('GET', '/admin/settings');
            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        } finally {
            $this->disableMaintenanceMode();
        }
    }

    /**
     * @brief Enable maintenance mode in persisted platform settings.
     *
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function enableMaintenanceMode(): void
    {
        $container = static::getContainer();
        $repository = $container->get(SiteAccessGateSettingsRepository::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        $settings = $repository->getOrCreateSingleton();
        $settings->setMaintenanceModeEnabled(true);
        $entityManager->flush();
    }

    /**
     * @brief Disable maintenance mode in persisted platform settings.
     *
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function disableMaintenanceMode(): void
    {
        $container = static::getContainer();
        $repository = $container->get(SiteAccessGateSettingsRepository::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        $settings = $repository->getOrCreateSingleton();
        $settings->setMaintenanceModeEnabled(false);
        $entityManager->flush();
    }

    /**
     * @brief Build in-memory user for security loginUser helper.
     *
     * @param list<string> $roles Role list.
     * @param int $id Synthetic user id.
     * @return User
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildUser(array $roles, int $id): User
    {
        $user = (new User())
            ->setEmail(sprintf('maintenance-test-%d@example.com', $id))
            ->setPseudonym(sprintf('maintenance-tester-%d', $id))
            ->setPassword('test')
            ->setRoles($roles)
            ->setActive(true)
            ->setSetupConfirmed(true);

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $id);

        return $user;
    }
}
