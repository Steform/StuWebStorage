<?php

declare(strict_types=1);

namespace App\Tests\Functional\Share;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for public download range streaming.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class PublicDownloadRangeTest extends TestCase
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
     * @brief Public file download uses encrypted stream delivery without prepare gate.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testPublicDownloadUsesEncryptedStreamDelivery(): void
    {
        $source = $this->readSource('src/Controller/PublicDownloadController.php');

        self::assertStringContainsString("methods: ['GET', 'HEAD']", $source);
        self::assertStringContainsString('encryptedStreamDeliveryService->buildEncryptedStreamResponse', $source);
        self::assertStringContainsString("'prepareRequired' => false", $source);
        self::assertStringNotContainsString('download_public_prepare_tick', $source);
    }
}
