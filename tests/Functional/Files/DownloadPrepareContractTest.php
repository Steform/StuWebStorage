<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\Controller\FilesController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Contract checks for streaming download routes in FilesController.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class DownloadPrepareContractTest extends TestCase
{
    /**
     * @brief Streaming download endpoints exist and prepared flow was removed.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testStreamingDownloadRoutesExistInController(): void
    {
        $ref = new ReflectionClass(FilesController::class);
        $path = $ref->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);

        self::assertStringContainsString('files_download_token', $source);
        self::assertStringContainsString('files_download_progress', $source);
        self::assertStringContainsString('encryptedStreamDeliveryService->buildEncryptedStreamResponse', $source);
        self::assertStringNotContainsString('files_download_prepare', $source);
        self::assertStringNotContainsString('download_prepare_page.html.twig', $source);
    }
}
