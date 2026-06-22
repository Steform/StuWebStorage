<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Ensures /files light-theme overrides stay in CSS (scoped html[data-bs-theme="light"]).
 */
class FilesSpaceLightThemeCssContractTest extends TestCase
{
    /**
     * @brief Read files-space.css from the repository root.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function readCss(): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.'/public/css/files-space.css';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Light theme overrides must remain scoped and cover files surfaces.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testFilesSpaceCssContainsLightThemeScopedBlock(): void
    {
        $src = $this->readCss();

        self::assertStringContainsString('html[data-bs-theme="light"] nav.files-top-nav.bg-body-tertiary', $src);
        self::assertStringContainsString('html[data-bs-theme="light"] .files-space-page .files-sections-accordion', $src);
        self::assertStringContainsString('html[data-bs-theme="light"] .files-space-page #files-listing-shell.files-listing-shell', $src);
        self::assertStringContainsString('files-grid-card-compact--chrome-less', $src);
        self::assertStringContainsString('html[data-bs-theme="light"] .files-space-page .files-owned-list-table', $src);
    }
}
