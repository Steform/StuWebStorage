<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Stub\TestUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @brief HTTP contract tests for the admin settings dashboard.
 */
final class AdminSettingsDashboardTest extends WebTestCase
{
    /**
     * @brief Reset in-memory test users between scenarios.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    protected function tearDown(): void
    {
        TestUserProvider::reset();
        parent::tearDown();
    }

    /**
     * @brief Non-admin users cannot access settings dashboard.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testNonAdminIsDeniedSettingsDashboard(): void
    {
        $user = $this->buildUser(['ROLE_USER', 'ROLE_SHARE'], 201);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $client->request('GET', '/admin/settings');

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    /**
     * @brief Admin users see configuration dashboard with access gate form.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testAdminSeesSettingsDashboard(): void
    {
        $user = $this->buildUser(['ROLE_ADMIN'], 202);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $client->request('GET', '/admin/settings');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Platform configuration', $content);
        self::assertStringContainsString('antibot_threshold', $content);
        self::assertStringContainsString('/admin/settings/antibot', $content);
        self::assertStringContainsString('maintenance_mode_enabled', $content);
        self::assertStringContainsString('/admin/settings/maintenance', $content);
        self::assertStringContainsString('Dashboard', $content);
        self::assertStringContainsString('floating-actions', $content);
        self::assertStringContainsString('admin-settings__disk-progress', $content);
        self::assertStringContainsString('bi-server', $content);
        self::assertStringNotContainsString('admin-settings__shortcut-link', $content);
    }

    /**
     * @brief Build in-memory user for security loginUser helper.
     *
     * @param list<string> $roles Role list.
     * @param int $id Synthetic user id.
     * @return User
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function buildUser(array $roles, int $id): User
    {
        $user = (new User())
            ->setEmail(sprintf('settings-test-%d@example.com', $id))
            ->setPseudonym(sprintf('settings-tester-%d', $id))
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
