<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for explicit target folder handling in admin all-users uploads.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class AdminAllUsersUploadFolderTargetContractTest extends TestCase
{
    /**
     * @brief Read source file from repository root.
     * @param string $relativePath Relative source path.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Upload controller must resolve explicit target folder and expose dedicated invalid-folder flash key.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testUploadControllerUsesTargetFolderResolver(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('private function resolveUploadTargetFolderId(Request $request, int $ownerId): array', $source);
        self::assertStringContainsString("request->request->get('target_folder_id'", $source);
        self::assertStringContainsString("['error' => 'files.flash.target_folder_invalid']", $source);
        self::assertStringContainsString('resolveUploadTargetFolderId($request, $ownerId)', $source);
    }

    /**
     * @brief Chunk upload service must reject invalid retained folders for the effective owner.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testChunkedUploadServiceRejectsInvalidFolderRetain(): void
    {
        $source = $this->readSource('src/Service/File/ChunkedUploadService.php');

        self::assertStringContainsString("throw new \\RuntimeException('chunk_upload.folder_invalid');", $source);
        self::assertStringContainsString('resolveCurrentFolder($ownerUserId, $folderRetainId)', $source);
        self::assertStringContainsString('resolveCurrentFolder($ownerId, $targetFolderId > 0 ? $targetFolderId : null)', $source);
    }

    /**
     * @brief Upload modal and locales must expose target folder field and translation key in all required languages.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testUploadTemplateAndLocalesExposeTargetFolderInvalidKey(): void
    {
        $template = $this->readSource('templates/files/index.html.twig');
        self::assertStringContainsString('name="target_folder_id"', $template);

        $locales = ['fr', 'en', 'de', 'lt', 'no'];
        foreach ($locales as $locale) {
            $messages = $this->readSource('translations/messages.'.$locale.'.yaml');
            self::assertStringContainsString('target_folder_invalid:', $messages);
        }
    }
}

