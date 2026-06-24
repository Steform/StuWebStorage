<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\ZipExtractService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Unit tests for ZIP extraction preflight scanning.
 * @author Stephane H.
 * @date 2026-06-24
 */
final class ZipExtractServiceTest extends TestCase
{
    /**
     * @return ZipExtractService
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildService(int $maxCompressionRatio = 100): ZipExtractService
    {
        $sharedFileRepository = $this->createMock(\App\Repository\SharedFileRepository::class);
        $userRepository = $this->createMock(\App\Repository\UserRepository::class);
        $quotaService = new \App\Service\File\UserStorageQuotaService($sharedFileRepository, $userRepository, 0);

        return new ZipExtractService(
            entityManager: $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            sharedFileRepository: $sharedFileRepository,
            folderRepository: $this->createMock(\App\Repository\FolderRepository::class),
            folderTreeService: $this->createMock(\App\Service\Share\FolderTreeService::class),
            fileEncryptionService: $this->createMock(\App\Service\File\FileEncryptionService::class),
            userStorageQuotaService: $quotaService,
            shareGrantRepository: $this->createMock(\App\Repository\ShareGrantRepository::class),
            publicDownloadChallengeRepository: $this->createMock(\App\Repository\PublicDownloadChallengeRepository::class),
            publicShareService: $this->createMock(\App\Service\Share\PublicShareService::class),
            friendsShareService: $this->createMock(\App\Service\Share\FriendsShareService::class),
            projectDir: sys_get_temp_dir(),
            maxTotalBytes: 1048576,
            maxFileCount: 100,
            maxSeconds: 30,
            batchSize: 5,
            maxCompressionRatio: $maxCompressionRatio,
        );
    }

    /**
     * @param list<array{name: string, content: string}> $entries
     * @return string Absolute path to temp zip.
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function createTempZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ziptest_');
        self::assertIsString($path);
        @unlink($path);
        $zipPath = $path.'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $entry) {
            $zip->addFromString($entry['name'], $entry['content']);
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * @param ZipExtractService $service Service instance.
     * @param string $zipPath ZIP path.
     * @return array{entries: list<array<string, mixed>>, file_count: int, total_bytes: int}
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function invokeScanZipArchive(ZipExtractService $service, string $zipPath): array
    {
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('scanZipArchive');
        $method->setAccessible(true);
        /** @var array{entries: list<array<string, mixed>>, file_count: int, total_bytes: int} $result */
        $result = $method->invoke($service, $zipPath, microtime(true));

        return $result;
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testScanZipArchiveCountsFilesAndSanitizesPaths(): void
    {
        $zipPath = $this->createTempZip([
            ['name' => 'docs/readme.txt', 'content' => 'hello'],
            ['name' => '../evil.txt', 'content' => 'bad'],
        ]);
        $service = $this->buildService();
        $scan = $this->invokeScanZipArchive($service, $zipPath);

        self::assertSame(2, $scan['file_count']);
        self::assertSame(8, $scan['total_bytes']);
        self::assertStringNotContainsString('..', (string) $scan['entries'][1]['sanitized_path']);
        @unlink($zipPath);
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testScanZipArchiveRejectsZipBombRatio(): void
    {
        $zipPath = $this->createTempZip([
            ['name' => 'bomb.bin', 'content' => str_repeat('A', 50000)],
        ]);
        $service = $this->buildService(maxCompressionRatio: 10);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zip_extract.limit_ratio');
        try {
            $this->invokeScanZipArchive($service, $zipPath);
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testMapExceptionToFlashKey(): void
    {
        self::assertSame(
            'files.flash.extract_not_zip',
            ZipExtractService::mapExceptionToFlashKey(new \RuntimeException('zip_extract.not_zip'))
        );
        self::assertSame(
            'files.flash.extract_failed',
            ZipExtractService::mapExceptionToFlashKey(new \RuntimeException('zip_extract.unknown'))
        );
    }
}
