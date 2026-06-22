<?php

declare(strict_types=1);

namespace App\Tests\Functional\Home;

use App\Entity\User;
use App\Tests\Stub\TestUserProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @brief HTTP contract tests for the cloud storage landing page.
 */
final class StorageHomePageTest extends WebTestCase
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
     * @brief Anonymous visitors see login CTA without storage stats.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testAnonymousHomePageShowsLoginWithoutStats(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Your encrypted cloud storage', $content);
        self::assertStringContainsString('/login', $content);
        self::assertStringNotContainsString('storage-stat-card', $content);
        self::assertStringContainsString('Per-user quota', $content);
        self::assertStringContainsString('floating-actions-locale-toggle', $content);
    }

    /**
     * @brief Authenticated users without ROLE_SHARE see disabled storage CTA.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUserWithoutShareRoleSeesDisabledStorageAccess(): void
    {
        $user = $this->buildUser(['ROLE_USER'], 101);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $client->request('GET', '/');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Storage access not enabled for your account', $content);
        self::assertStringNotContainsString('storage-stat-card', $content);
    }

    /**
     * @brief Users with ROLE_SHARE see stats and files entry point.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUserWithShareRoleSeesStatsAndFilesLink(): void
    {
        $user = $this->buildUser(['ROLE_USER', 'ROLE_SHARE'], 102);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $client->request('GET', '/');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('storage-stat-card', $content);
        self::assertStringContainsString('Your storage statistics', $content);
        self::assertStringContainsString('/files', $content);
        self::assertStringContainsString('Open my files', $content);
    }

    /**
     * @brief Admin users see admin files shortcut on landing page.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testAdminHomePageShowsAdminFilesLink(): void
    {
        $user = $this->buildUser(['ROLE_ADMIN'], 103);
        TestUserProvider::register($user);

        $client = static::createClient();
        $client->loginUser($user, 'main');
        $client->request('GET', '/');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/admin/files', $content);
        self::assertStringContainsString('All files (admin)', $content);
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
            ->setEmail(sprintf('home-test-%d@example.com', $id))
            ->setPseudonym(sprintf('home-tester-%d', $id))
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
