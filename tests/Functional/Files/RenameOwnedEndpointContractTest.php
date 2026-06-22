<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for owned-file rename endpoint.
 */
class RenameOwnedEndpointContractTest extends TestCase
{
    /**
     * @brief Read a repository file content as string for static assertions.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-04-30
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Ensure the files controller exposes secured owned rename flow with CSRF and ownership checks.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-30
     * @author Stephane H.
     */
    public function testFilesControllerDefinesOwnedRenameEndpointContract(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("private const CSRF_RENAME = 'files_rename';", $source);
        self::assertStringContainsString("#[Route('/files/{id}/rename', name: 'files_rename', methods: ['POST']", $source);
        self::assertStringContainsString("new CsrfToken(self::CSRF_RENAME", $source);
        self::assertStringContainsString("files.flash.not_owner", $source);
        self::assertStringContainsString("files.flash.rename_name_required", $source);
        self::assertStringContainsString("files.flash.rename_name_too_long", $source);
        self::assertStringContainsString("files.flash.renamed", $source);
    }
}
