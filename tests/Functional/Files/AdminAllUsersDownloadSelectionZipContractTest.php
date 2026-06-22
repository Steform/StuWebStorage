<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for admin all-users selection zip owner resolution.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class AdminAllUsersDownloadSelectionZipContractTest extends TestCase
{
    /**
     * @brief Read repository file contents.
     * @param string $relativePath Path relative to repository root.
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
     * @brief Download selection zip must resolve an effective owner for admin godview all-users.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testDownloadSelectionZipResolvesEffectiveOwnerInGodview(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('public function downloadSelectionZip(Request $request): Response', $source);
        self::assertStringContainsString('tryResolveEffectiveOwnerIdForAdminSubject($request, true)', $source);
        self::assertStringContainsString("addFlash('danger', 'files.flash.godview_subject_invalid')", $source);
        self::assertStringContainsString('collectSubtreeFolders($effectiveOwnerId, $folder)', $source);
    }

    /**
     * @brief Folder branch of selection zip must validate folder owner against effective owner id.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testDownloadSelectionZipUsesEffectiveOwnerForFolderFilter(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('(int) $folder->getOwnerUserId() !== $effectiveOwnerId', $source);
        self::assertStringContainsString("'ownerUserId' => \$effectiveOwnerId", $source);
    }

    /**
     * @brief Frontend download-selection query must propagate admin all-users context with subject user.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesSpaceDownloadSelectionPropagatesAdminContext(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function downloadSharedSelection(ids, folderIds, selectionContext)', $source);
        self::assertStringContainsString("params.set('admin_context', '1');", $source);
        self::assertStringContainsString("params.set('admin_view_scope', 'all');", $source);
        self::assertStringContainsString("params.set('subject_user', ctx.subjectUserId.trim());", $source);
    }
}

