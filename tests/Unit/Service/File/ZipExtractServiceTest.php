<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Dto\File\ZipExtractLimits;
use App\Service\File\FolderPathMaterializerService;
use App\Service\File\ZipExtractLimitsResolver;
use App\Service\File\ZipExtractService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @brief Unit tests for ZIP extraction preflight scanning and limits resolution.
 * @author Stephane H.
 * @date 2026-06-24
 */
final class ZipExtractServiceTest extends TestCase
{
    /**
     * @return ZipExtractLimits
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function standardLimits(int $maxCompressionRatio = 100): ZipExtractLimits
    {
        return new ZipExtractLimits(
            maxTotalBytes: 1048576,
            maxFileCount: 100,
            maxSeconds: 30,
            batchSize: 5,
            maxCompressionRatio: $maxCompressionRatio,
            tier: ZipExtractLimitsResolver::TIER_STANDARD,
        );
    }

    /**
     * @return ZipExtractService
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildService(): ZipExtractService
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
            folderPathMaterializerService: new FolderPathMaterializerService(
                $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
                $sharedFileRepository,
                $this->createMock(\App\Repository\FolderRepository::class),
                $this->createMock(\App\Service\Share\FolderAncestorService::class),
            ),
            projectDir: sys_get_temp_dir(),
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
     * @param ZipExtractLimits $limits Limits under test.
     * @return array{entries: list<array<string, mixed>>, file_count: int, total_bytes: int}
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function invokeScanZipArchive(ZipExtractService $service, string $zipPath, ZipExtractLimits $limits): array
    {
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('scanZipArchive');
        $method->setAccessible(true);
        /** @var array{entries: list<array<string, mixed>>, file_count: int, total_bytes: int} $result */
        $result = $method->invoke($service, $zipPath, microtime(true), $limits);

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
        $scan = $this->invokeScanZipArchive($service, $zipPath, $this->standardLimits());

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
        $service = $this->buildService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zip_extract.limit_ratio');
        try {
            $this->invokeScanZipArchive($service, $zipPath, $this->standardLimits(maxCompressionRatio: 10));
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function testLimitsResolverReturnsAdminTier(): void
    {
        $resolver = new ZipExtractLimitsResolver(
            maxTotalBytes: 100,
            adminMaxTotalBytes: 999,
            maxFileCount: 10,
            adminMaxFileCount: 20,
            maxSeconds: 30,
            adminMaxSeconds: 60,
            batchSize: 5,
            maxCompressionRatio: 100,
        );

        $admin = $resolver->resolveForActor(true);
        self::assertSame(999, $admin->maxTotalBytes);
        self::assertSame(ZipExtractLimitsResolver::TIER_ADMIN, $admin->tier);
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
