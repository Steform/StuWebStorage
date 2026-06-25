<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contracts for chunked upload routes on FilesController.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class ChunkUploadRoutesContractTest extends TestCase
{
    /**
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function readController(): string
    {
        $path = dirname(__DIR__, 3).'/src/Controller/FilesController.php';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function readChunkedUploadService(): string
    {
        $path = dirname(__DIR__, 3).'/src/Service/File/ChunkedUploadService.php';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testChunkEndpointsDeclarePostRoutesUnderFilesUpload(): void
    {
        $src = $this->readController();

        self::assertStringContainsString("#[Route('/files/upload/session'", $src);
        self::assertStringContainsString("#[Route('/files/upload/chunk'", $src);
        self::assertStringContainsString("#[Route('/files/upload/finalize'", $src);
        self::assertStringContainsString('function uploadSession(', $src);
        self::assertStringContainsString('function uploadChunk(', $src);
        self::assertStringContainsString('function uploadFinalize(', $src);
        self::assertStringContainsString('resolveUploadTargetFolderId($request, $ownerId)', $src);
        self::assertStringContainsString("'chunk_upload.folder_invalid' => 'files.flash.target_folder_invalid'", $src);
        self::assertStringContainsString("'chunk_upload.quota_exceeded', 'storage_quota.exceeded' => 'files.flash.quota_exceeded'", $src);
        self::assertStringContainsString('assertOwnerCanStoreBytes($ownerId', $src);
        self::assertStringContainsString('createSession(', $src);
        self::assertStringContainsString("'relative_path'", $src);
        self::assertStringContainsString("'chunk_upload.path_invalid' => 'files.flash.upload_path_invalid'", $src);
        self::assertStringContainsString('finalizeAndPersist($ownerId, $uploadId, $maxUploadBytes)', $src);
        self::assertStringContainsString('resolveUploadOrFolderTargetOwnerId', $src);

        $chunked = $this->readChunkedUploadService();
        self::assertStringContainsString('folderPathMaterializerService', $chunked);
        self::assertStringContainsString("'relative_path'", $chunked);
        self::assertStringContainsString('chunk_upload.path_invalid', $chunked);
    }
}
