<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contracts for ZIP extraction routes on FilesController.
 * @author Stephane H.
 * @date 2026-06-24
 */
final class ZipExtractRoutesContractTest extends TestCase
{
    /**
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function readController(): string
    {
        $path = dirname(__DIR__, 3).'/src/Controller/FilesController.php';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function readAppFilesYaml(): string
    {
        $path = dirname(__DIR__, 3).'/config/packages/app_files.yaml';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function readServicesYaml(): string
    {
        $path = dirname(__DIR__, 3).'/config/services.yaml';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testExtractEndpointsDeclareRoutes(): void
    {
        $src = $this->readController();

        self::assertStringContainsString("private const CSRF_EXTRACT = 'files_extract';", $src);
        self::assertStringContainsString("#[Route('/files/{id}/extract/preflight'", $src);
        self::assertStringContainsString("#[Route('/files/{id}/extract'", $src);
        self::assertStringContainsString("#[Route('/files/extract/{jobId}/tick'", $src);
        self::assertStringContainsString("#[Route('/files/extract/{jobId}/cancel'", $src);
        self::assertStringContainsString('function extractZipPreflight(', $src);
        self::assertStringContainsString('$this->zipExtractLimitsResolver->resolveForActor', $src);
        self::assertStringContainsString('$this->zipExtractService->createJob(', $src);
        self::assertStringContainsString('$limits)', $src);
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testZipExtractLimitsAreConfiguredFromEnv(): void
    {
        $yaml = $this->readAppFilesYaml();

        self::assertStringContainsString('APP_ZIP_EXTRACT_MAX_TOTAL_BYTES', $yaml);
        self::assertStringContainsString('APP_ZIP_EXTRACT_ADMIN_MAX_TOTAL_BYTES', $yaml);
        self::assertStringContainsString('app.zip_extract_admin_max_total_bytes:', $yaml);
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testZipExtractLimitsResolverIsRegistered(): void
    {
        $yaml = $this->readServicesYaml();

        self::assertStringContainsString('App\Service\File\ZipExtractLimitsResolver:', $yaml);
        self::assertStringContainsString("$adminMaxTotalBytes: '%app.zip_extract_admin_max_total_bytes%'", $yaml);
    }
}
