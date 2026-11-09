<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for EPIC-ENC upload encrypt progress and task center.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class UploadEncryptProgressContractTest extends TestCase
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
     * @brief Core assets and labels for encryption progress exist.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testEncryptProgressWiringExists(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/src/Service/File/UploadEncryptJobService.php');
        self::assertFileExists($root.'/public/js/files-upload-encrypt.js');
        self::assertFileExists($root.'/public/js/files-background-tasks.js');
        self::assertFileExists($root.'/templates/files/_upload_encrypt_dismiss_modal.html.twig');
        self::assertFileExists($root.'/.cursor/rules/crypto-progress-eta.mdc');

        $enc = $this->readSource('src/Service/File/FileEncryptionService.php');
        self::assertStringContainsString('encryptPlainFileToV2StorageTick', $enc);
        self::assertStringContainsString('file.encryption.cancelled', $enc);

        $uploadEncryptJs = $this->readSource('public/js/files-upload-encrypt.js');
        self::assertStringContainsString('pollUntilTerminal', $uploadEncryptJs);
        self::assertStringContainsString('cancelTask', $uploadEncryptJs);
        self::assertStringContainsString('window.FilesUploadEncrypt', $uploadEncryptJs);

        $space = $this->readSource('public/js/files-space.js');
        self::assertStringContainsString('FilesUploadEncrypt.pollUntilTerminal', $space);
        self::assertStringContainsString('onEncryptProgress', $space);
        self::assertStringContainsString('filesUploadEncryptDismissModal', $space);
        self::assertStringContainsString('files:open-upload-task', $space);
        self::assertStringNotContainsString('pollUploadEncryptTask', $space);
        self::assertStringNotContainsString('window.confirm(body', $space);

        $tasksJs = $this->readSource('public/js/files-background-tasks.js');
        self::assertStringContainsString('files:open-upload-task', $tasksJs);
        self::assertStringContainsString('FilesUploadEncrypt.cancelTask', $tasksJs);

        $fr = $this->readSource('translations/messages.fr.yaml');
        self::assertStringContainsString("processing: 'Chiffrement…'", $fr);
        self::assertStringContainsString('disk_full:', $fr);
        self::assertStringContainsString('dismiss_continue:', $fr);

        $index = $this->readSource('templates/files/index.html.twig');
        self::assertStringContainsString("_upload_encrypt_dismiss_modal.html.twig", $index);

        $base = $this->readSource('templates/base.html.twig');
        self::assertStringContainsString('files-upload-encrypt.js', $base);
        self::assertStringContainsString('files-background-tasks.js', $base);

        $nav = $this->readSource('templates/components/_storage_nav.html.twig');
        self::assertStringContainsString('files-background-tasks', $nav);
        self::assertStringContainsString('files_tasks_list', $nav);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('files_upload_encrypt_tick', $controller);
        self::assertStringContainsString('files_upload_encrypt_cancel', $controller);
        self::assertStringContainsString('UploadEncryptJobService', $controller);

        $dismiss = $this->readSource('templates/files/_upload_encrypt_dismiss_modal.html.twig');
        self::assertStringContainsString('data-files-encrypt-dismiss-continue', $dismiss);
        self::assertStringContainsString('data-files-encrypt-dismiss-cancel', $dismiss);
        self::assertStringContainsString('data-files-encrypt-dismiss-stay', $dismiss);
    }
}
