<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Share;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Service\Share\SharedForMeTreeService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @brief Unit tests for grantee-side shared folder tree navigation.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class SharedForMeTreeServiceTest extends TestCase
{
    private SharedForMeTreeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SharedForMeTreeService();
    }

    /**
     * @brief Assign a synthetic identifier to a folder entity for tests.
     * @param Folder $folder Folder entity.
     * @param int $id Identifier.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function assignFolderId(Folder $folder, int $id): void
    {
        $property = new ReflectionProperty(Folder::class, 'id');
        $property->setAccessible(true);
        $property->setValue($folder, $id);
    }

    /**
     * @brief Assign a synthetic identifier to a shared file entity for tests.
     * @param SharedFile $sharedFile Shared file entity.
     * @param int $id Identifier.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function assignSharedFileId(SharedFile $sharedFile, int $id): void
    {
        $property = new ReflectionProperty(SharedFile::class, 'id');
        $property->setAccessible(true);
        $property->setValue($sharedFile, $id);
    }

    /**
     * @brief Build word/2026/doc.docx shared fixture.
     * @return array{word: Folder, year: Folder, file: SharedFile}
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildWordYearDocFixture(): array
    {
        $word = new Folder(10, 'word');
        $this->assignFolderId($word, 100);
        $year = new Folder(10, '2026', $word);
        $this->assignFolderId($year, 101);
        $file = new SharedFile(10, '/storage/doc.docx', 'private', 'token-doc', 'doc.docx', 2048);
        $file->setFolder($year);
        $this->assignSharedFileId($file, 500);

        return ['word' => $word, 'year' => $year, 'file' => $file];
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testRootListsOnlyTopLevelSharedFolders(): void
    {
        $fixture = $this->buildWordYearDocFixture();
        $context = $this->service->buildListingContext([$fixture['file']], 0);

        self::assertSame(0, $context->currentFolderId);
        self::assertSame([['id' => 100, 'name' => 'word']], $context->foldersAtLevel);
        self::assertSame([], $context->filesAtLevel);
        self::assertSame([], $context->breadcrumbFolders);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testIntermediateFolderShowsChildFoldersWithoutDirectFiles(): void
    {
        $fixture = $this->buildWordYearDocFixture();
        $context = $this->service->buildListingContext([$fixture['file']], 100);

        self::assertSame(100, $context->currentFolderId);
        self::assertSame([['id' => 101, 'name' => '2026']], $context->foldersAtLevel);
        self::assertSame([], $context->filesAtLevel);
        self::assertSame([['id' => 100, 'name' => 'word']], $context->breadcrumbFolders);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testLeafFolderListsSharedFiles(): void
    {
        $fixture = $this->buildWordYearDocFixture();
        $context = $this->service->buildListingContext([$fixture['file']], 101);

        self::assertSame(101, $context->currentFolderId);
        self::assertSame([], $context->foldersAtLevel);
        self::assertCount(1, $context->filesAtLevel);
        self::assertSame(500, $context->filesAtLevel[0]->getId());
        self::assertSame(
            [
                ['id' => 100, 'name' => 'word'],
                ['id' => 101, 'name' => '2026'],
            ],
            $context->breadcrumbFolders
        );
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testInvalidFolderCursorResetsToRoot(): void
    {
        $fixture = $this->buildWordYearDocFixture();
        $context = $this->service->buildListingContext([$fixture['file']], 999);

        self::assertSame(0, $context->currentFolderId);
        self::assertSame([['id' => 100, 'name' => 'word']], $context->foldersAtLevel);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testDistinctOwnersKeepSeparateFolderIds(): void
    {
        $ownerOneWord = new Folder(1, '2026');
        $this->assignFolderId($ownerOneWord, 201);
        $ownerTwoWord = new Folder(2, '2026');
        $this->assignFolderId($ownerTwoWord, 202);

        $fileOne = new SharedFile(1, '/a', 'private', 't1', 'a.txt', 10);
        $fileOne->setFolder($ownerOneWord);
        $fileTwo = new SharedFile(2, '/b', 'private', 't2', 'b.txt', 20);
        $fileTwo->setFolder($ownerTwoWord);

        $context = $this->service->buildListingContext([$fileOne, $fileTwo], 0);

        self::assertCount(2, $context->foldersAtLevel);
        self::assertSame(
            [
                ['id' => 201, 'name' => '2026'],
                ['id' => 202, 'name' => '2026'],
            ],
            $context->foldersAtLevel
        );
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testRecursiveFolderSizesIncludeDescendantFiles(): void
    {
        $fixture = $this->buildWordYearDocFixture();
        $context = $this->service->buildListingContext([$fixture['file']], 0);

        self::assertSame(2048, $context->folderSizeBytes[100]);
        self::assertSame(2048, $context->folderSizeBytes[101]);
    }

    /**
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testRootListsFilesWithoutFolder(): void
    {
        $rootFile = new SharedFile(10, '/root.txt', 'private', 'token-root', 'root.txt', 100);
        $this->assignSharedFileId($rootFile, 1);

        $context = $this->service->buildListingContext([$rootFile], 0);

        self::assertCount(1, $context->filesAtLevel);
        self::assertSame(1, $context->filesAtLevel[0]->getId());
    }
}
