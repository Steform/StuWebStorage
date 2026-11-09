<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract: in-page OPFS/FS Access resume stack is no longer the primary path.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class FilesDownloadCheckpointContractTest extends TestCase
{
    /**
     * @brief Read repository source.
     * @param string $relativePath Relative path.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Files space uses grant-backed native downloads, not checkpoint assembly.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testFilesSpaceUsesNativeGrantLauncher(): void
    {
        $spaceJs = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('requestGrantAndDownload', $spaceJs);
        self::assertStringNotContainsString('FilesDownloadCheckpoint', $spaceJs);
        self::assertStringNotContainsString('FilesDownloadSinks', $spaceJs);
    }

    /**
     * @brief Delivery routes expose grant-based /dl endpoints.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testDeliveryControllerExposesDlRoutes(): void
    {
        $source = $this->readSource('src/Controller/DownloadDeliveryController.php');
        self::assertStringContainsString("/dl/file/{id}", $source);
        self::assertStringContainsString("/dl/archive/{token}", $source);
        self::assertStringContainsString('RangeStreamResponder', $source);
    }
}
