<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for sibling name uniqueness (files + folders, normalized).
 */
class NameUniquenessContractTest extends TestCase
{
    /**
     * @brief Read a repository file content as string for static assertions.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Repository exposes normalized-name collision lookup for owned files in one folder level.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSharedFileRepositoryDefinesConflictLookup(): void
    {
        $source = $this->readSource('src/Repository/SharedFileRepository.php');

        self::assertStringContainsString('findConflictingOwnedFileByNormalizedName', $source);
        self::assertStringContainsString('Folder::normalizeName', $source);
    }

    /**
     * @brief Files controller wires upload/rename/createFolder against sibling checks and flash keys.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilesControllerDefinesNameUniquenessGuards(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('siblingFolderExistsWithNormalizedName', $source);
        self::assertStringContainsString('findConflictingOwnedFileByNormalizedName', $source);
        self::assertStringContainsString('files.flash.upload_name_conflict', $source);
        self::assertStringContainsString('files.flash.name_conflict_same_level', $source);
        self::assertStringContainsString('files.folder.flash.name_conflict_with_file', $source);
    }
}
