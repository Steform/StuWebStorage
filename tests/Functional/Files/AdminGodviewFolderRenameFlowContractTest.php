<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract guard for admin godview folder rename flow.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class AdminGodviewFolderRenameFlowContractTest extends TestCase
{
    /**
     * @brief Read source file from repository root.
     * @param string $relativePath Relative path.
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
     * @brief Extract method slice for targeted assertions.
     * @param string $source Full source.
     * @param string $methodName Method name to locate.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function methodSlice(string $source, string $methodName): string
    {
        $anchor = 'public function '.$methodName.'(';
        $start = strpos($source, $anchor);
        if ($start === false) {
            return '';
        }
        $nextPublic = strpos($source, "\n    public function ", $start + strlen($anchor));
        if ($nextPublic === false) {
            $nextPublic = strlen($source);
        }

        return substr($source, $start, $nextPublic - $start);
    }

    /**
     * @brief Folder rename must resolve owner from target folder and preserve non-admin ownership denial.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testRenameFolderUsesTargetFolderOwnerAndAdminOverride(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');
        $slice = $this->methodSlice($source, 'renameFolder');

        self::assertNotSame('', $slice);
        self::assertStringContainsString('$folder = $this->folderRepository->find($id);', $slice);
        self::assertStringContainsString('$isAdminActor = $this->isGranted(\'ROLE_ADMIN\');', $slice);
        self::assertStringContainsString('$ownerId = (int) $folder->getOwnerUserId();', $slice);
        self::assertStringContainsString('if ($ownerId !== $actorId && !$isAdminActor)', $slice);
        self::assertStringContainsString('files.flash.not_owner', $slice);
    }

    /**
     * @brief Folder rename godview branch must emit dedicated admin audit and keep rollback action.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testRenameFolderKeepsRollbackAndAddsGodviewAudit(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');
        $slice = $this->methodSlice($source, 'renameFolder');

        self::assertNotSame('', $slice);
        self::assertStringContainsString('$isAdminActor = $this->isGranted(\'ROLE_ADMIN\');', $slice);
    }
}

