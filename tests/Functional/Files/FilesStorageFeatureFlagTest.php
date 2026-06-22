<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Service\Admin\RoleGovernanceService;
use App\Service\File\FilesStorageFeatureService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @brief Compliance and HTTP checks for APP_FILES_STORAGE_ENABLED feature flag.
 */
final class FilesStorageFeatureFlagTest extends WebTestCase
{
    /**
     * @brief Resolve project root directory.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-09
     * @author Stephane H.
     */
    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @brief Static wiring must expose env parameter, subscriber, Twig helper, and UI guards.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testFeatureFlagWiringCompliance(): void
    {
        $config = @file_get_contents(self::projectRoot().'/config/packages/app_files.yaml') ?: '';
        self::assertStringContainsString('APP_FILES_STORAGE_ENABLED', $config);
        self::assertStringContainsString('app.files_storage_enabled', $config);

        $subscriber = @file_get_contents(self::projectRoot().'/src/EventSubscriber/FilesStorageGateSubscriber.php') ?: '';
        self::assertStringContainsString('HTTP_NOT_FOUND', $subscriber);
        self::assertStringContainsString('/admin/files', $subscriber);
        self::assertStringContainsString('/download/public', $subscriber);

        $twigExtension = @file_get_contents(self::projectRoot().'/src/Twig/FilesStorageFeatureExtension.php') ?: '';
        self::assertStringContainsString('files_storage_enabled', $twigExtension);

        $homeIndex = @file_get_contents(self::projectRoot().'/templates/home/index.html.twig') ?: '';
        self::assertStringContainsString("path('files_index')", $homeIndex);

        $nav = @file_get_contents(self::projectRoot().'/templates/components/_storage_nav.html.twig') ?: '';
        self::assertStringContainsString("path('files_index')", $nav);
        $base = @file_get_contents(self::projectRoot().'/templates/base.html.twig') ?: '';
        self::assertStringContainsString('_floating_actions.html.twig', $base);

        $adminNav = @file_get_contents(self::projectRoot().'/templates/components/_storage_admin_nav.html.twig') ?: '';
        self::assertStringContainsString("path('admin_files_index')", $adminNav);
    }

    /**
     * @brief Disabled module must return 404 on protected HTTP routes.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testDisabledModuleReturns404OnHttpRoutes(): void
    {
        $client = $this->createClientWithFilesStorageEnabled(false);

        foreach (['/files', '/admin/files', '/p/example-token'] as $path) {
            $client->request('GET', $path);
            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $client->getResponse()->getStatusCode(),
                'Expected 404 for '.$path,
            );
        }

        $client->request('POST', '/download/public/challenge');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    /**
     * @brief Role governance must hide share roles when storage is disabled.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function testRoleGovernanceFiltersShareRolesWhenDisabled(): void
    {
        $repository = $this->createMock(\App\Repository\UserRepository::class);
        $service = new RoleGovernanceService(
            $repository,
            new FilesStorageFeatureService(false),
            ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SHARE', 'ROLE_SHARE_SEND', 'ROLE_SHARE_PUBLIC', 'ROLE_SHARE_FRIENDS'],
        );

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $service->getAllowedRoles());
    }

    /**
     * @brief Boot a test client with APP_FILES_STORAGE_ENABLED overridden.
     *
     * @param bool $enabled Feature flag value.
     * @return KernelBrowser
     * @date 2026-06-09
     * @author Stephane H.
     */
    private function createClientWithFilesStorageEnabled(bool $enabled): KernelBrowser
    {
        $value = $enabled ? '1' : '0';
        $_ENV['APP_FILES_STORAGE_ENABLED'] = $value;
        $_SERVER['APP_FILES_STORAGE_ENABLED'] = $value;
        putenv('APP_FILES_STORAGE_ENABLED='.$value);

        static::ensureKernelShutdown();

        return static::createClient();
    }
}
