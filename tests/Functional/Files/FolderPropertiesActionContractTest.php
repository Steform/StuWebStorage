<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for folder properties and folder share actions in FilesController.
 */
final class FolderPropertiesActionContractTest extends TestCase
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
     * @brief Folder properties action must expose route, ownership check and recursive aggregate payload.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFolderPropertiesActionContractExists(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("name: 'files_folder_properties'", $source);
        self::assertStringContainsString('public function folderProperties(', $source);
        self::assertStringContainsString('resolveCurrentFolder($ownerId, $id)', $source);
        self::assertStringContainsString('buildRecursiveProperties($ownerId, $folder)', $source);
        self::assertStringContainsString("'total_bytes_formatted' => \$this->binaryByteFormatter->format(", $source);
    }

    /**
     * @brief Folder properties service must compute recursive file count, size and subfolder count.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testFolderPropertiesServiceComputesRecursiveAggregates(): void
    {
        $source = $this->readSource('src/Service/Share/FolderPropertiesService.php');

        self::assertStringContainsString('collectSubtreeFolders', $source);
        self::assertStringContainsString('countByOwnerAndFolderIds', $source);
        self::assertStringContainsString('sumByteSizeByOwnerAndFolderIds', $source);
        self::assertStringContainsString('countActivePublicWithFiniteExpiryByOwnerAndFolderIds', $source);
        self::assertStringContainsString('isPublicShareEffectivelyActive', $source);
        self::assertStringContainsString('\'totalSubfolders\' => max(0, count($folderIds) - 1)', $source);
    }

    /**
     * @brief Upload into a shared folder must not revive friends access when prior subtree grants are all expired.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderUploadFriendsSyncUsesSubtreeGrantTemplate(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('hasAnyGrantForOwnerFolderSubtreeGrantee', $controller);
        self::assertStringContainsString('findOneActiveGrantForOwnerFolderSubtreeGrantee', $controller);

        $repo = $this->readSource('src/Repository/ShareGrantRepository.php');
        self::assertStringContainsString('function hasAnyGrantForOwnerFolderSubtreeGrantee', $repo);
        self::assertStringContainsString('function findOneActiveGrantForOwnerFolderSubtreeGrantee', $repo);
    }

    /**
     * @brief Owner listing and share state must use active friend grants only, and folder friends flag must ignore stale JSON when files exist.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testActiveFriendsGrantsOnlyInOwnerListingAndFolderState(): void
    {
        $props = $this->readSource('src/Service/Share/FolderPropertiesService.php');
        self::assertStringContainsString('$friendsActive = $friendsCount > 0 || ($filesInSubtree === 0 && $hasFolderFriendsPolicy)', $props);

        $grants = $this->readSource('src/Repository/ShareGrantRepository.php');
        self::assertStringContainsString('function findActiveGranteeIdsBySharedFile', $grants);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('findActiveGranteeIdsBySharedFile', $controller);
        self::assertStringContainsString('findActiveBySharedFile', $controller);
    }
}
