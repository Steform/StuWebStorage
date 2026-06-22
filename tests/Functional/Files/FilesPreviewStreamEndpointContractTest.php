<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for inline preview stream (PDF, native video/audio, plain-text MIME allowlist).
 * @date 2026-05-06
 * @author Stephane H.
 */
final class FilesPreviewStreamEndpointContractTest extends TestCase
{
    /**
     * @brief Read a repository file content as string for static assertions.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Ensure preview uses allowlist MIME map, same auth path, and inline disposition.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesControllerDefinesPreviewStreamEndpointContract(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("name: 'files_preview'", $source);
        self::assertStringContainsString('/files/preview/{id}', $source);
        self::assertStringContainsString('PREVIEW_STREAM_BY_EXTENSION', $source);
        self::assertStringContainsString('canUserDownloadSharedFile', $source);
        self::assertStringContainsString("'pdf' => ['mime' => 'application/pdf'", $source);
        self::assertStringContainsString("'mp4' => ['mime' => 'video/mp4'", $source);
        self::assertStringContainsString("'mp3' => ['mime' => 'audio/mpeg'", $source);
        self::assertStringContainsString("'txt' => ['mime' => 'text/plain; charset=utf-8'", $source);
        self::assertStringContainsString("'md' => ['mime' => 'text/plain; charset=utf-8'", $source);
        self::assertStringContainsString("'json' => ['mime' => 'text/plain; charset=utf-8'", $source);
        self::assertStringContainsString("'kind' => 'text'", $source);
        self::assertStringContainsString('MAX_TEXT_PREVIEW_BYTES = 20971520', $source);
        self::assertStringContainsString('$streamProfile = self::PREVIEW_STREAM_BY_EXTENSION', $source);
        self::assertStringContainsString("'Content-Type', \$streamProfile['mime']", $source);
        self::assertStringContainsString("'Content-Disposition', 'inline;", $source);
        self::assertStringContainsString('downloadAuditService->create', $source);
        self::assertStringContainsString("(\$streamProfile['kind'] ?? '') === 'text'", $source);
        self::assertStringContainsString('$sharedFile->getByteSize() > self::MAX_TEXT_PREVIEW_BYTES', $source);
        self::assertStringContainsString("Response::HTTP_REQUEST_ENTITY_TOO_LARGE", $source);
        self::assertStringContainsString("'X-Content-Type-Options', 'nosniff'", $source);
        self::assertStringContainsString("'Cache-Control', 'private, max-age=0, no-store'", $source);
    }
}
