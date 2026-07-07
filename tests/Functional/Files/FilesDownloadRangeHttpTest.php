<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for authenticated download range streaming.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class FilesDownloadRangeHttpTest extends TestCase
{
    /**
     * @brief Read repository source for static assertions.
     * @param string $relativePath Relative path from repository root.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Download endpoint delegates to encrypted stream delivery with HEAD support.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testDownloadControllerUsesEncryptedStreamDelivery(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("methods: ['GET', 'HEAD']", $source);
        self::assertStringContainsString('encryptedStreamDeliveryService->buildEncryptedStreamResponse', $source);
        self::assertStringContainsString('files_download_token', $source);
        self::assertStringContainsString('files_download_progress', $source);
        self::assertStringNotContainsString('download_prepare_page.html.twig', $source);
    }
}
