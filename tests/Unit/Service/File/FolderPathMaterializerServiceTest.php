<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\Folder;
use App\Service\File\FolderPathMaterializerService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for folder path materialization during upload and ZIP extraction.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class FolderPathMaterializerServiceTest extends TestCase
{
    /**
     * @return FolderPathMaterializerService
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildService(
        ?\App\Repository\FolderRepository $folderRepository = null,
        ?\App\Repository\SharedFileRepository $sharedFileRepository = null,
    ): FolderPathMaterializerService {
        return new FolderPathMaterializerService(
            entityManager: $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            sharedFileRepository: $sharedFileRepository ?? $this->createMock(\App\Repository\SharedFileRepository::class),
            folderRepository: $folderRepository ?? $this->createMock(\App\Repository\FolderRepository::class),
        );
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testSplitRelativeFilePathSeparatesFoldersAndFileName(): void
    {
        $service = $this->buildService();
        $split = $service->splitRelativeFilePath('MonProjet/src/utils/helper.js');

        self::assertSame('MonProjet/src/utils', $split['folder_path']);
        self::assertSame('helper.js', $split['file_name']);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testSplitRelativeFilePathNormalizesBackslashes(): void
    {
        $service = $this->buildService();
        $split = $service->splitRelativeFilePath('docs\\readme.txt');

        self::assertSame('docs', $split['folder_path']);
        self::assertSame('readme.txt', $split['file_name']);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testEnsureFolderPathFromRelativeRejectsParentTraversal(): void
    {
        $service = $this->buildService();
        $result = $service->ensureFolderPathFromRelative(
            1,
            null,
            'src/../evil',
            FolderPathMaterializerService::CONFLICT_ABORT
        );

        self::assertSame(FolderPathMaterializerService::CONFLICT_ABORT, $result);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testEnsureFolderPathFromRelativeReturnsBaseWhenPathEmpty(): void
    {
        $service = $this->buildService();
        $base = new Folder(1, 'root', null);

        self::assertSame($base, $service->ensureFolderPathFromRelative(
            1,
            $base,
            '',
            FolderPathMaterializerService::CONFLICT_ABORT
        ));
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testHasFileNameConflictDetectsExistingFile(): void
    {
        $sharedFileRepository = $this->createMock(\App\Repository\SharedFileRepository::class);
        $sharedFileRepository->method('findConflictingOwnedFileByNormalizedName')
            ->willReturn($this->createMock(\App\Entity\SharedFile::class));
        $folderRepository = $this->createMock(\App\Repository\FolderRepository::class);
        $folderRepository->method('findOneByOwnerParentAndNormalizedName')->willReturn(null);

        $service = $this->buildService($folderRepository, $sharedFileRepository);

        self::assertTrue($service->hasFileNameConflict(1, null, 'report.pdf'));
    }
}
