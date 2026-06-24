<?php

declare(strict_types=1);

namespace App\Tests\Functional\File;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contracts for per-user storage quota wiring.
 */
final class UserStorageQuotaWiringContractTest extends TestCase
{
    /**
     * @brief Ensure quota service, entity field, and upload guards are wired.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testQuotaFeatureWiringCompliance(): void
    {
        $root = dirname(__DIR__, 3);

        $entity = (string) file_get_contents($root.'/src/Entity/User.php');
        self::assertStringContainsString('storageQuotaBytes', $entity);

        $service = (string) file_get_contents($root.'/src/Service/File/UserStorageQuotaService.php');
        self::assertStringContainsString('assertOwnerCanStoreBytes', $service);

        $chunked = (string) file_get_contents($root.'/src/Service/File/ChunkedUploadService.php');
        self::assertStringContainsString('chunk_upload.quota_exceeded', $chunked);

        $admin = (string) file_get_contents($root.'/templates/admin/users/show.html.twig');
        self::assertStringContainsString('storage_quota_gib', $admin);

        $home = (string) file_get_contents($root.'/templates/home/index.html.twig');
        self::assertStringContainsString('stat_remaining_help_user', $home);
        self::assertStringContainsString('feature_quota_title', $home);
        self::assertStringContainsString('quotaSource', $home);
    }
}
