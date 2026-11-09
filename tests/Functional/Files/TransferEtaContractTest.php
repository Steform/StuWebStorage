<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for shared transfer ETA module wiring (EPIC-ETA).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class TransferEtaContractTest extends TestCase
{
    /**
     * @brief Read repository source for static assertions.
     * @param string $relativePath Relative path from repository root.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Shared ETA module exposes the estimator API used by all transfer UIs.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testTransferEtaModuleApiExists(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/public/js/transfer-eta.js');
        $src = $this->readSource('public/js/transfer-eta.js');
        self::assertStringContainsString('createEstimator', $src);
        self::assertStringContainsString('formatLine', $src);
        self::assertStringContainsString('formatDuration', $src);
        self::assertStringContainsString('formatSpeedValue', $src);
        self::assertStringContainsString('bytesPerSec', $src);
        self::assertStringContainsString('window.TransferEta', $src);
        self::assertFileExists($root.'/.cursor/rules/transfer-eta.mdc');
        $rule = $this->readSource('.cursor/rules/transfer-eta.mdc');
        self::assertStringContainsString('débit/s', $rule);
        self::assertStringContainsString('US-08', $rule);
    }

    /**
     * @brief Upload / download / archive UIs load and call the shared estimator.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testTransferEtaIsWiredIntoUploadDownloadAndArchive(): void
    {
        $index = $this->readSource('templates/files/index.html.twig');
        self::assertStringContainsString('js/transfer-eta.js', $index);
        self::assertStringContainsString('files-upload-global-eta', $index);
        self::assertStringContainsString('data-msg-multi-eta-calculating', $index);

        $space = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('TransferEta', $space);
        self::assertStringContainsString('createEstimator', $space);
        self::assertStringContainsString('files-upload-global-eta', $space);

        $progressPage = $this->readSource('templates/files/download_progress_page.html.twig');
        self::assertStringContainsString('js/transfer-eta.js', $progressPage);
        self::assertStringContainsString('files-download-progress-eta', $progressPage);

        $progressJs = $this->readSource('public/js/files-download-progress-page.js');
        self::assertStringContainsString('TransferEta', $progressJs);
        self::assertStringContainsString('downloadEta', $progressJs);

        $archiveModal = $this->readSource('templates/files/_archive_progress_modal.html.twig');
        self::assertStringContainsString('files-archive-progress-eta', $archiveModal);
        self::assertStringContainsString('data-msg-eta-calculating', $archiveModal);

        $archiveJs = $this->readSource('public/js/files-archive-flow.js');
        self::assertStringContainsString('TransferEta', $archiveJs);
        self::assertStringContainsString('prepareEta', $archiveJs);

        $launcher = $this->readSource('public/js/files-download-launcher.js');
        self::assertStringContainsString('EPIC-ETA', $launcher);
        self::assertStringContainsString('in-page ETA is impossible here', $launcher);
    }

    /**
     * @brief French and English i18n keys required for ETA labels exist.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testTransferEtaI18nKeysExist(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $yaml = $this->readSource('translations/messages.'.$locale.'.yaml');
            self::assertStringContainsString('eta_calculating:', $yaml);
            self::assertStringContainsString('eta_remaining:', $yaml);
            self::assertStringContainsString('eta_speed:', $yaml);
            self::assertStringContainsString('eta_line:', $yaml);
            self::assertStringContainsString('%speed%', $yaml);
            self::assertStringContainsString('eta_note_native:', $yaml);
        }
    }
}
