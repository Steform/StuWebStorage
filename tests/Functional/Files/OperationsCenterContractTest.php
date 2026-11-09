<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for EPIC-OPS operations center and Famille B chrome.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class OperationsCenterContractTest extends TestCase
{
    /**
     * @brief Read repository source.
     * @param string $relativePath Relative path.
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
     * @brief Chrome, nav, bus, API, and per-type executors exist.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testOperationsCenterWiringExists(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/templates/operations/_operation_modal_chrome.html.twig');
        self::assertFileExists($root.'/templates/operations/_operation_header_actions.html.twig');
        self::assertFileExists($root.'/templates/operations/_ops_dismiss_modal.html.twig');
        self::assertFileExists($root.'/templates/files/_download_operation_modal.html.twig');
        self::assertFileExists($root.'/public/js/files-operations.js');
        self::assertFileExists($root.'/public/js/files-operations-center.js');
        self::assertFileExists($root.'/public/js/files-download-operation.js');
        self::assertFileExists($root.'/.cursor/rules/operations-center.mdc');
        self::assertFileExists($root.'/src/Service/File/OperationsListService.php');
    }

    /**
     * @brief Famille B templates expose Reduce; Famille A rename/delete do not.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testFamilleBModalsIncludeReduceChrome(): void
    {
        $upload = $this->readSource('templates/files/index.html.twig');
        $extract = $this->readSource('templates/files/_extract_zip_modal.html.twig');
        $archive = $this->readSource('templates/files/_archive_progress_modal.html.twig');
        $download = $this->readSource('templates/files/_download_operation_modal.html.twig');
        foreach ([$upload, $extract, $archive, $download] as $src) {
            self::assertTrue(
                str_contains($src, 'data-ops-reduce') || str_contains($src, '_operation_header_actions.html.twig'),
                'Famille B modal must include Reduce chrome',
            );
            self::assertStringContainsString('data-ops-family="b"', $src);
        }

        $rename = $this->readSource('templates/files/_rename_modal.html.twig');
        $delete = $this->readSource('templates/files/_delete_confirm_modal.html.twig');
        self::assertStringNotContainsString('data-ops-reduce', $rename);
        self::assertStringNotContainsString('data-ops-reduce', $delete);
        self::assertStringNotContainsString('data-ops-family="b"', $rename);
    }

    /**
     * @brief Nav places the operations center next to Accueil and uses GET /files/operations.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testOperationsCenterNavPlacementAndApi(): void
    {
        $nav = $this->readSource('templates/components/_storage_nav.html.twig');
        $brandPos = strpos($nav, 'navbar-brand');
        $opsPos = strpos($nav, '_operations_center_nav.html.twig');
        $collapsePos = strpos($nav, 'storageNavCollapse');
        self::assertNotFalse($brandPos);
        self::assertNotFalse($opsPos);
        self::assertNotFalse($collapsePos);
        self::assertGreaterThan($brandPos, $opsPos);
        self::assertGreaterThan($opsPos, $collapsePos);
        self::assertStringNotContainsString('files-background-tasks', $nav);

        $opsNav = $this->readSource('templates/components/_operations_center_nav.html.twig');
        self::assertStringContainsString('files_operations_list', $opsNav);
        self::assertStringContainsString('filesOperationsCenterToggle', $opsNav);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString("'/files/operations'", $controller);
        self::assertStringContainsString('OperationsListService', $controller);

        $archiveController = $this->readSource('src/Controller/DownloadArchiveController.php');
        self::assertStringContainsString('files_archives_cancel', $archiveController);
        self::assertStringContainsString('cancelPrepare', $archiveController);
    }

    /**
     * @brief JS bus exposes resume/reduce/cancel for all four operation types.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testExecutorsRegisterResumeAndRealCancel(): void
    {
        $bus = $this->readSource('public/js/files-operations.js');
        self::assertStringContainsString('window.FilesOperations', $bus);
        self::assertStringContainsString('function resume', $bus);
        self::assertStringContainsString('function reduce', $bus);
        self::assertStringContainsString('opsDismissModal', $bus);

        $space = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString("register('upload_encrypt'", $space);
        self::assertStringContainsString("register('zip_extract'", $space);
        self::assertStringContainsString('FilesDownloadOperation.open', $space);
        self::assertStringContainsString('hydrate:', $space);
        self::assertStringNotContainsString('filesUploadEncryptDismissModal', $space);
        self::assertStringNotContainsString("window.confirm(confirmMsg)", $space);
        self::assertStringNotContainsString('FilesDownloadManager.download', $space);
        self::assertStringNotContainsString('files_download_progress', $space);

        $archive = $this->readSource('public/js/files-archive-flow.js');
        self::assertStringContainsString("register('archive_prepare'", $archive);
        self::assertStringContainsString('/cancel', $archive);
        self::assertStringContainsString('FilesDownloadOperation.open', $archive);

        $download = $this->readSource('public/js/files-download-operation.js');
        self::assertStringContainsString("register('download_file'", $download);
        self::assertStringContainsString("register('download_archive'", $download);
        self::assertStringContainsString('startNativeDownload', $download);
        self::assertStringContainsString('handed_to_browser', $download);
        self::assertStringContainsString('copyResumableLink', $download);

        $encrypt = $this->readSource('public/js/files-upload-encrypt.js');
        self::assertStringContainsString('FilesOperations.upsert', $encrypt);
        self::assertStringContainsString('cancelTask', $encrypt);
    }

    /**
     * @brief i18n files.ops keys exist in fr and en.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testOpsI18nFrEn(): void
    {
        foreach (['fr', 'en', 'de', 'lt', 'no'] as $locale) {
            $yaml = $this->readSource('translations/messages.'.$locale.'.yaml');
            self::assertStringContainsString('  ops:', $yaml);
            self::assertStringContainsString('nav_label:', $yaml);
            self::assertStringContainsString('handed:', $yaml);
            self::assertStringContainsString('upload_encrypt:', $yaml);
        }
    }
}
