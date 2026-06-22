<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Static regression: bulk move and folder children routes exist on FilesController.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class BulkMoveRoutesTest extends TestCase
{
    /**
     * @brief FilesController must declare POST files_move_bulk and GET files_folder_children.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testFilesControllerDeclaresBulkMoveAndChildrenRoutes(): void
    {
        $path = dirname(__DIR__, 3).'/src/Controller/FilesController.php';
        $src = is_readable($path) ? (string) file_get_contents($path) : '';

        self::assertStringContainsString("/files/move/bulk", $src);
        self::assertStringContainsString("name: 'files_move_bulk'", $src);
        self::assertStringContainsString("/files/folders/children", $src);
        self::assertStringContainsString("name: 'files_folder_children'", $src);
        self::assertStringContainsString('CSRF_MOVE_BULK', $src);
        self::assertStringContainsString('files_move_bulk', $src);
        self::assertStringContainsString('function moveBulk', $src);
        self::assertStringContainsString('function folderChildren', $src);
        self::assertStringContainsString('function isMoveTargetInsideFolderSubtree', $src);
    }
}
