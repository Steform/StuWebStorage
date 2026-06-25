<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the minimal upload modal contract (Sprint 21+).
 */
class FilesUploadModalIntegrationTest extends TestCase
{
    /**
     * @brief Ensure files index exposes upload modal and upload-only controls without inline sharing fields.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesIndexTemplateReferencesMinimalUploadModal(): void
    {
        $path = dirname(__DIR__, 3).'/templates/files/index.html.twig';
        $twig = is_file($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString('id="filesUploadModal"', $twig);
        self::assertStringContainsString('id="files-open-upload-modal"', $twig);
        self::assertStringContainsString('id="files-display-menu-toggle"', $twig);
        self::assertStringContainsString('files.toolbar.display_menu_label', $twig);
        self::assertStringContainsString('dropend', $twig);
        self::assertStringContainsString('data-files-listing-scope="owned"', $twig);
        self::assertStringContainsString('data-files-view-toggle="list"', $twig);
        self::assertStringContainsString('id="file-upload"', $twig);
        self::assertStringContainsString('multiple', $twig);
        self::assertStringContainsString('id="files-upload-queue"', $twig);
        self::assertStringContainsString('id="files-upload-global-progress"', $twig);
        self::assertStringContainsString('id="files-upload-folder-input"', $twig);
        self::assertStringContainsString('webkitdirectory', $twig);
        self::assertStringContainsString('files.upload.multi.modal_title', $twig);
        self::assertStringContainsString('files-upload-modal-footer', $twig);
        self::assertStringContainsString('files-upload-modal-content', $twig);
        self::assertStringContainsString('data-files-upload-queue-detail-limit', $twig);
        self::assertStringNotContainsString('name="visibility"', $twig);
        self::assertStringNotContainsString('name="expires_at"', $twig);
        self::assertStringNotContainsString('name="grantee_ids"', $twig);
        self::assertStringNotContainsString('id="files-grantee-suggestions"', $twig);
        self::assertStringNotContainsString('filesUploadCollapse', $twig);
        self::assertStringContainsString('id="files-upload-progress-wrap"', $twig);
        self::assertStringContainsString('data-msg-progress-sending=', $twig);
        self::assertStringContainsString('id="files-upload-error"', $twig);
        self::assertStringContainsString('data-files-upload-submit', $twig);
        self::assertStringContainsString('data-files-upload-session-url', $twig);
        self::assertStringContainsString('data-files-upload-chunk-url', $twig);
        self::assertStringContainsString('data-files-upload-finalize-url', $twig);
        self::assertStringContainsString('name="target_folder_id"', $twig);
        self::assertStringContainsString('id="files-upload-selected-meta"', $twig);
        self::assertStringContainsString('files.upload.selected_byte_size_label', $twig);
        self::assertStringContainsString('files.upload.bytes_hint', $twig);
        self::assertStringContainsString('maxUploadBytes|files_size_format', $twig);
        self::assertStringContainsString("'%formatted_size%':", $twig);
        self::assertStringNotContainsString("'%bytes%': maxUploadBytes", $twig);

        $cssPath = dirname(__DIR__, 3).'/public/css/files-space.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        self::assertStringContainsString('.files-upload-modal-footer', $css);
        self::assertStringContainsString('#files-upload-queue-wrap', $css);
    }

    /**
     * @brief Ensure files-space wires XHR upload progress handler.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesSpaceJsDeclaresUploadProgressBinding(): void
    {
        $path = dirname(__DIR__, 3).'/public/js/files-space.js';
        $src = is_file($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString('function bindFilesUploadProgress', $src);
        self::assertStringContainsString('function uploadFileQueue', $src);
        self::assertStringContainsString('function uploadSingleFile', $src);
        self::assertStringContainsString('function assignFilesToInput', $src);
        self::assertStringContainsString('bindFilesUploadProgress(form, fileInput, uploadModalEl)', $src);
        self::assertStringContainsString('function formatFilesSize', $src);
        self::assertStringContainsString('filesUploadSessionUrl', $src);
        self::assertStringContainsString('function syncUploadTargetFolderInput', $src);
        self::assertStringContainsString('function openMoveBulkModal', $src);
        self::assertStringContainsString('move-selection', $src);
        self::assertStringContainsString('function submitMoveBulkFormAsJson', $src);
    }

    /**
     * @brief Ensure grantee suggest action is guarded for friends sharing role.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testFilesControllerDeclaresGranteeSuggestRoute(): void
    {
        $path = dirname(__DIR__, 3).'/src/Controller/FilesController.php';
        $src = is_file($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString("name: 'files_grantee_search'", $src);
        self::assertStringContainsString('/files/grantees/suggest', $src);
        self::assertStringContainsString("#[IsGranted('ROLE_SHARE_SEND')]", $src);
        self::assertStringContainsString("#[IsGranted('ROLE_SHARE_FRIENDS')]", $src);
        self::assertStringContainsString('function granteeSuggest', $src);
    }

    /**
     * @brief Ensure user repository exposes grant autocomplete query helper.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testUserRepositoryHasGrantSuggestSearch(): void
    {
        $path = dirname(__DIR__, 3).'/src/Repository/UserRepository.php';
        $src = is_file($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString('function searchActiveUsersForGrantSuggest', $src);
        self::assertStringNotContainsString('u.id != :exclude', $src);
        self::assertStringContainsString('u.roles LIKE :roleAdmin', $src);
    }
}
