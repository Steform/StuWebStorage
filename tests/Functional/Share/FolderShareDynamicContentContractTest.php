<?php

declare(strict_types=1);

namespace App\Tests\Functional\Share;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for dynamic folder-level friends sharing.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class FolderShareDynamicContentContractTest extends TestCase
{
    /**
     * @brief Read repository source.
     * @param string $relativePath Relative file path.
     * @return string
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Folder share endpoint must upsert folder grants instead of recursive file grants.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testFolderShareFriendsUsesFolderGrantIntent(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');
        $folderShareService = $this->readSource('src/Service/Share/FolderShareService.php');

        self::assertStringContainsString('applyFolderFriendsIntent', $controller);
        self::assertStringNotContainsString('applyFriendsRecursive', $controller);
        self::assertStringNotContainsString('applyFriendsRecursive', $folderShareService);
    }

    /**
     * @brief Grantee listing must union file grants and folder subtree grants.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSharedForGranteeListingUsesFolderAncestorUnion(): void
    {
        $repository = $this->readSource('src/Repository/SharedFileRepository.php');

        self::assertStringContainsString('folder_share_grant', $repository);
        self::assertStringContainsString('folder_ancestor', $repository);
        self::assertStringContainsString('UNION', $repository);
    }

    /**
     * @brief Upload paths must not snapshot folder policies onto each file anymore.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testUploadFlowDoesNotApplyPerFileFolderPolicies(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');
        $zipExtract = $this->readSource('src/Service/File/ZipExtractService.php');

        self::assertStringNotContainsString('applyFolderPoliciesToUploadedFile', $controller);
        self::assertStringNotContainsString('applyFolderPoliciesToUploadedFile', $zipExtract);
    }

    /**
     * @brief Shared tree service must include folder grant subtrees for empty shared folders.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSharedForMeTreeIncludesFolderGrantSubtrees(): void
    {
        $source = $this->readSource('src/Service/Share/SharedForMeTreeService.php');

        self::assertStringContainsString('findActiveByGrantee', $source);
        self::assertStringContainsString('collectSubtreeFolders', $source);
    }
}
