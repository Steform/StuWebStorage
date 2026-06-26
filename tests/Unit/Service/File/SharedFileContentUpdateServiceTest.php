<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\SharedFileRepository;
use App\Repository\UserRepository;
use App\Service\File\FileEncryptionService;
use App\Service\File\SharedFileContentUpdateService;
use App\Service\File\UserStorageQuotaService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for plaintext content updates on owned shared files.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class SharedFileContentUpdateServiceTest extends TestCase
{
    /**
     * @brief Saving valid UTF-8 content re-encrypts storage and updates byte size.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSaveContentReencryptsAndUpdatesByteSize(): void
    {
        $encryption = new FileEncryptionService(str_repeat('k', 32));
        $quota = $this->buildQuotaService(ownerUserId: 7, usedBytes: 100, userQuotaBytes: 10000);

        $service = new SharedFileContentUpdateService($encryption, $quota, 1024 * 1024);

        $plainSeed = tempnam(sys_get_temp_dir(), 'sws_seed_');
        $storagePath = tempnam(sys_get_temp_dir(), 'sws_store_');
        self::assertIsString($plainSeed);
        self::assertIsString($storagePath);

        try {
            file_put_contents($plainSeed, 'old');
            $encryption->encryptPlainFileToV2Storage($plainSeed, $storagePath);

            $sharedFile = new SharedFile(7, $storagePath, 'private', 'token-notes-txt', 'notes.txt', 3);
            $newSize = $service->saveContent($sharedFile, "new\n", 7);

            self::assertSame(4, $newSize);
            self::assertSame(4, $sharedFile->getByteSize());
            self::assertSame("new\n", $encryption->decryptFromStorage($storagePath));
        } finally {
            @unlink($plainSeed);
            @unlink($storagePath);
        }
    }

    /**
     * @brief Disallowed extensions are rejected before touching storage.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSaveContentRejectsNonEditableExtension(): void
    {
        $service = new SharedFileContentUpdateService(
            new FileEncryptionService(str_repeat('k', 32)),
            $this->buildQuotaService(),
        );

        $sharedFile = new SharedFile(1, '/tmp/x', 'private', 'token-pdf', 'doc.pdf', 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SharedFileContentUpdateService::EXCEPTION_EXTENSION_NOT_ALLOWED);

        $service->saveContent($sharedFile, 'hello', 1);
    }

    /**
     * @brief Oversized payloads are rejected using configured max bytes.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSaveContentRejectsTooLargePayload(): void
    {
        $service = new SharedFileContentUpdateService(
            new FileEncryptionService(str_repeat('k', 32)),
            $this->buildQuotaService(),
            4,
        );

        $sharedFile = new SharedFile(1, '/tmp/x', 'private', 'token-txt', 'a.txt', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(SharedFileContentUpdateService::EXCEPTION_TOO_LARGE);

        $service->saveContent($sharedFile, '12345', 1);
    }

    /**
     * @brief Quota enforcement propagates storage quota exceeded errors.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testSaveContentPropagatesQuotaExceeded(): void
    {
        $service = new SharedFileContentUpdateService(
            new FileEncryptionService(str_repeat('k', 32)),
            $this->buildQuotaService(usedBytes: 999, userQuotaBytes: 1000),
            1024,
        );

        $sharedFile = new SharedFile(1, '/tmp/x', 'private', 'token-txt', 'a.txt', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED);

        $service->saveContent($sharedFile, str_repeat('x', 100), 1);
    }

    /**
     * @brief Build quota service with mocked aggregate usage.
     * @param int $usedBytes Simulated used bytes for owner 1.
     * @param int|null $userQuotaBytes Effective quota for owner 1.
     * @return UserStorageQuotaService
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function buildQuotaService(int $ownerUserId = 1, int $usedBytes = 0, ?int $userQuotaBytes = null): UserStorageQuotaService
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($usedBytes);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $sharedFileRepository = $this->createMock(SharedFileRepository::class);
        $sharedFileRepository->method('createQueryBuilder')->willReturn($qb);

        $user = (new User())->setStorageQuotaBytes($userQuotaBytes);
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $ownerUserId);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->with($ownerUserId)->willReturn($user);

        return new UserStorageQuotaService($sharedFileRepository, $userRepository, 0);
    }
}
