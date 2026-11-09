<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Share;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Repository\FolderShareGrantRepository;
use App\Repository\ShareGrantRepository;
use App\Service\Share\FolderShareAuthorizationService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for folder and file friends authorization.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class FolderShareAuthorizationServiceTest extends TestCase
{
    /**
     * @brief Owner always has access.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testOwnerCanAccessOwnFile(): void
    {
        $service = $this->buildService(false, false);
        $folder = new Folder(10, 'series');
        $file = new SharedFile(10, '/f', 'private', 'tok', 'ep.mkv', 100);
        $file->setFolder($folder);

        self::assertTrue($service->canAccessFileViaFriends($file, 10));
    }

    /**
     * @brief Active file grant allows access.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testFileGrantAllowsAccess(): void
    {
        $service = $this->buildService(true, false);
        $file = new SharedFile(10, '/f', 'private', 'tok', 'ep.mkv', 100);

        self::assertTrue($service->canAccessFileViaFriends($file, 20));
    }

    /**
     * @brief Folder ancestor grant allows access without file grant.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testFolderGrantAllowsAccessWithoutFileGrant(): void
    {
        $service = $this->buildService(false, true);
        $folder = new Folder(10, 'season');
        $property = new \ReflectionProperty(Folder::class, 'id');
        $property->setAccessible(true);
        $property->setValue($folder, 55);
        $file = new SharedFile(10, '/f', 'private', 'tok', 'ep.mkv', 100);
        $file->setFolder($folder);

        self::assertTrue($service->canAccessFileViaFriends($file, 20));
    }

    /**
     * @brief Stranger without grants is denied.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testStrangerDeniedWithoutGrants(): void
    {
        $service = $this->buildService(false, false);
        $file = new SharedFile(10, '/f', 'private', 'tok', 'ep.mkv', 100);

        self::assertFalse($service->canAccessFileViaFriends($file, 20));
    }

  /**
   * @param bool $fileGrantActive Whether file grant check is active.
   * @param bool $folderGrantActive Whether folder grant check is active.
   * @return FolderShareAuthorizationService
   * @date 2026-06-26
   * @author Stephane H.
   */
    private function buildService(bool $fileGrantActive, bool $folderGrantActive): FolderShareAuthorizationService
    {
        $shareGrantRepository = $this->createMock(ShareGrantRepository::class);
        $shareGrantRepository->method('isFriendsGrantActiveAtDatabaseNow')->willReturn($fileGrantActive);

        $folderShareGrantRepository = $this->createMock(FolderShareGrantRepository::class);
        $folderShareGrantRepository->method('hasActiveGrantForFileFolder')->willReturn($folderGrantActive);

        return new FolderShareAuthorizationService($shareGrantRepository, $folderShareGrantRepository);
    }
}
