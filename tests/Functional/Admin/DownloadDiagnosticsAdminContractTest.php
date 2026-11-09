<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DownloadDiagnosticsAdminController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Contract test for admin download diagnostics routes and exports.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class DownloadDiagnosticsAdminContractTest extends TestCase
{
    /**
     * @brief Controller source defines diagnostics listing and export routes.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testRoutesExistInControllerSource(): void
    {
        $ref = new ReflectionClass(DownloadDiagnosticsAdminController::class);
        $file = $ref->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertStringContainsString("name: 'admin_download_diagnostics_index'", $source);
        self::assertStringContainsString("name: 'admin_download_diagnostics_export'", $source);
        self::assertStringContainsString("name: 'admin_download_diagnostics_show'", $source);
        self::assertStringContainsString('Content-Disposition', $source);
    }
}
