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
    public function testExtractEndpointsDeclarePostRoutes(): void
    {
        $src = $this->readController();

        self::assertStringContainsString("private const CSRF_EXTRACT = 'files_extract';", $src);
        self::assertStringContainsString("#[Route('/files/{id}/extract'", $src);
        self::assertStringContainsString("#[Route('/files/extract/{jobId}/tick'", $src);
        self::assertStringContainsString("#[Route('/files/extract/{jobId}/cancel'", $src);
        self::assertStringContainsString('function extractZipStart(', $src);
        self::assertStringContainsString('function extractZipTick(', $src);
        self::assertStringContainsString('function extractZipCancel(', $src);
        self::assertStringContainsString('$this->zipExtractService->createJob(', $src);
        self::assertStringContainsString('$this->zipExtractService->tickJob(', $src);
        self::assertStringContainsString('$this->zipExtractService->cancelJob(', $src);
        self::assertStringContainsString("'csrfExtract' => self::CSRF_EXTRACT", $src);
        self::assertStringContainsString('ZipExtractService::mapExceptionToFlashKey', $src);
        self::assertStringContainsString('canActorMutateOwnedSharedFile', $src);
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testZipExtractServiceIsRegisteredWithLimits(): void
    {
        $yaml = $this->readServicesYaml();

        self::assertStringContainsString('app.zip_extract_max_total_bytes:', $yaml);
        self::assertStringContainsString('app.zip_extract_max_file_count:', $yaml);
        self::assertStringContainsString('app.zip_extract_max_seconds:', $yaml);
        self::assertStringContainsString('app.zip_extract_batch_size:', $yaml);
        self::assertStringContainsString('app.zip_extract_max_compression_ratio:', $yaml);
        self::assertStringContainsString('App\Service\File\ZipExtractService:', $yaml);
        self::assertStringContainsString("$maxTotalBytes: '%app.zip_extract_max_total_bytes%'", $yaml);
    }
}
