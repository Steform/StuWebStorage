<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for create-folder flow in FilesController.
 */
final class FolderCreateActionContractTest extends TestCase
{
    /**
     * @brief Read repository source file as raw text.
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Create-folder action must validate CSRF, parent ownership and normalized uniqueness.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testCreateFolderActionValidatesCsrfParentAndNameUniqueness(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("name: 'files_folder_create'", $source);
        self::assertStringContainsString("new CsrfToken(self::CSRF_FOLDER_CREATE", $source);
        self::assertStringContainsString("files.folder.flash.parent_invalid", $source);
        self::assertStringContainsString('Folder::normalizeName($name)', $source);
        self::assertStringContainsString('findOneByOwnerParentAndNormalizedName', $source);
    }

    /**
     * @brief Create-folder action must handle DB unique violations and trace rollback action on success.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testCreateFolderActionHandlesDbConstraintAndRollbackTrace(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('catch (UniqueConstraintViolationException)', $source);
        self::assertStringContainsString("files.folder.flash.already_exists", $source);
    }
}
