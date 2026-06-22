<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for empty shared folder visibility in shared-for-me listing.
 * @date 2026-05-06
 * @author Stephane H.
 */
final class EmptySharedFolderVisibilityContractTest extends TestCase
{
    /**
     * @brief Read a repository file as raw source.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-06
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Shared pane builder must derive visible shared folders from active files and their folder lineage only.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testPaneBuilderBuildsSharedFoldersFromActiveFilesLineage(): void
    {
        $source = $this->readSource('src/Service/File/UserFilesPaneBuilderService.php');

        self::assertStringContainsString('buildActiveSharedForMeFolders', $source);
        self::assertStringContainsString('$folderCursor = $sharedForMeFile->getFolder();', $source);
        self::assertStringContainsString('$folderCursor = $folderCursor->getParent();', $source);
        self::assertStringNotContainsString('findFriendsSharedFoldersForGrantee', $source);
        self::assertStringNotContainsString('$sharedForMeIntentFolders', $source);
        self::assertStringContainsString("'hasSharedForMe' => \$allSharedForMeFiles !== [] || \$sharedForMeFolders !== []", $source);
    }

    /**
     * @brief Folder repository must expose active grantee-based folder lookup driven by active file grants.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFolderRepositoryExposesActiveFriendsSharedFoldersLookup(): void
    {
        $source = $this->readSource('src/Repository/FolderRepository.php');

        self::assertStringContainsString('public function findActiveFriendsSharedFoldersForGrantee', $source);
        self::assertStringContainsString('sg.expiresAt IS NULL OR sg.expiresAt > CURRENT_TIMESTAMP()', $source);
        self::assertStringContainsString('sf.expiresAt IS NULL OR sf.expiresAt > CURRENT_TIMESTAMP()', $source);
        self::assertStringContainsString('sf.ownerUserId != :granteeUserId', $source);
    }
}

