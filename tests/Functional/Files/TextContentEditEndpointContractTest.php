<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static contract checks for owned text content save endpoint.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class TextContentEditEndpointContractTest extends TestCase
{
    /**
     * @brief Read a repository file content as string for static assertions.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Ensure FilesController exposes secured text content save flow.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testFilesControllerDefinesTextContentSaveEndpointContract(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("private const CSRF_CONTENT_EDIT = 'files_content_edit';", $source);
        self::assertStringContainsString("#[Route('/files/{id}/content', name: 'files_content_save', methods: ['POST']", $source);
        self::assertStringContainsString('new CsrfToken(self::CSRF_CONTENT_EDIT', $source);
        self::assertStringContainsString('canActorMutateOwnedSharedFile($user, $sharedFile)', $source);
        self::assertStringContainsString('files.flash.not_owner', $source);
        self::assertStringContainsString('files.flash.content_saved', $source);
        self::assertStringContainsString('files.flash.content_too_large', $source);
        self::assertStringContainsString('files.flash.content_invalid_utf8', $source);
        self::assertStringContainsString('files.flash.content_extension_not_allowed', $source);
        self::assertStringContainsString('SharedFileContentUpdateService', $source);
        self::assertStringContainsString("'X-CSRF-Token'", $source);
    }
}
