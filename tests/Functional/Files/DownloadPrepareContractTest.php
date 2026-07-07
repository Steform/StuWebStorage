<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Controller\FilesController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Contract checks for prepared download routes in FilesController.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class DownloadPrepareContractTest extends TestCase
{
    /**
     * @brief Prepared download endpoints and threshold branch exist in controller source.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testPreparedDownloadRoutesExistInController(): void
    {
        $ref = new ReflectionClass(FilesController::class);
        $path = $ref->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString('files_download_prepare', $source);
        self::assertStringContainsString('files_download_prepare_tick', $source);
        self::assertStringContainsString('files_download_prepare_cancel', $source);
        self::assertStringContainsString('files_download_prepare_deliver', $source);
        self::assertStringContainsString('requiresPreparedDownload', $source);
        self::assertStringContainsString('download_prepare_page.html.twig', $source);
        self::assertStringContainsString('BinaryFileResponse', $source);
        self::assertStringContainsString('deleteFileAfterSend(true)', $source);
    }
}
