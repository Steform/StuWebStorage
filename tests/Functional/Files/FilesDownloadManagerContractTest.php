<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for native download launcher and archive flow assets.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class FilesDownloadManagerContractTest extends TestCase
{
    /**
     * @brief Read repository source for static assertions.
     * @param string $relativePath Relative path from repository root.
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
     * @brief Native launcher and archive flow assets are wired into the files space.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testDownloadLauncherAssetsExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/public/js/files-download-launcher.js');
        self::assertFileExists($root.'/public/js/files-archive-flow.js');
        self::assertFileExists($root.'/templates/files/_archive_progress_modal.html.twig');

        $spaceJs = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('FilesDownloadLauncher', $spaceJs);
        self::assertStringContainsString('FilesArchiveFlow', $spaceJs);
        self::assertStringContainsString('data-files-archives-create-url', $this->readSource('templates/files/index.html.twig'));
        self::assertStringContainsString('startNativeDownload', $this->readSource('public/js/files-download-launcher.js'));
        self::assertStringNotContainsString('showSaveFilePicker', $this->readSource('public/js/files-download-launcher.js'));
        self::assertStringNotContainsString('arrayBuffer()', $this->readSource('public/js/files-download-launcher.js'));
    }
}
