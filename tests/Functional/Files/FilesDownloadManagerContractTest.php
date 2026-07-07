<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for multi-chunk download assembly assumptions.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class FilesDownloadManagerContractTest extends TestCase
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
     * @brief Download manager and progress page assets are wired.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testDownloadManagerAssetsExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/public/js/files-download-manager.js');
        self::assertFileExists($root.'/public/js/files-download-progress-page.js');
        self::assertFileExists($root.'/templates/files/download_progress_page.html.twig');

        $spaceJs = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('data-files-download-progress-url-template', $spaceJs);
        self::assertStringContainsString('FilesDownloadManager', $this->readSource('public/js/files-download-progress-page.js'));
    }
}
