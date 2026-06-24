<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\User;
use App\Repository\SharedFileRepository;
use App\Repository\UserRepository;
use App\Service\File\UserStorageQuotaService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for per-user storage quota resolution and enforcement.
 */
final class UserStorageQuotaServiceTest extends TestCase
{
    /**
     * @brief Personal quota overrides platform default.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testPersonalQuotaOverridesDefault(): void
    {
        $user = (new User())->setStorageQuotaBytes(5 * 1024 * 1024 * 1024);
        $service = $this->buildService(defaultQuotaBytes: 100 * 1024 * 1024 * 1024, usedBytes: 1024);

        self::assertSame(5 * 1024 * 1024 * 1024, $service->resolveEffectiveQuotaBytes($user));
        self::assertSame(UserStorageQuotaService::QUOTA_SOURCE_USER, $service->resolveQuotaSource($user));
        self::assertSame((5 * 1024 * 1024 * 1024) - 1024, $service->resolveRemainingBytes($user, 1024));
    }

    /**
     * @brief Null user quota inherits platform default.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testDefaultQuotaIsUsedWhenUserQuotaIsNull(): void
    {
        $user = new User();
        $service = $this->buildService(defaultQuotaBytes: 1024, usedBytes: 100);

        self::assertSame(1024, $service->resolveEffectiveQuotaBytes($user));
        self::assertSame(UserStorageQuotaService::QUOTA_SOURCE_DEFAULT, $service->resolveQuotaSource($user));
        self::assertSame(924, $service->resolveRemainingBytes($user, 100));
    }

    /**
     * @brief Zero quota means unlimited storage.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testUnlimitedQuotaReturnsNullRemainingBytes(): void
    {
        $user = (new User())->setStorageQuotaBytes(0);
        $service = $this->buildService(defaultQuotaBytes: 1024, usedBytes: 9999);

        self::assertSame(0, $service->resolveEffectiveQuotaBytes($user));
        self::assertSame(UserStorageQuotaService::QUOTA_SOURCE_UNLIMITED, $service->resolveQuotaSource($user));
        self::assertNull($service->resolveRemainingBytes($user, 9999));
    }

    /**
     * @brief Upload beyond remaining quota throws dedicated exception message.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testAssertOwnerCanStoreBytesThrowsWhenQuotaExceeded(): void
    {
        $user = (new User())->setStorageQuotaBytes(1000);
        $this->setUserId($user, 42);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->with(42)->willReturn($user);

        $service = $this->buildService(defaultQuotaBytes: 0, usedBytes: 900, userRepository: $userRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED);

        $service->assertOwnerCanStoreBytes(42, 200);
    }

    /**
     * @brief Admin GiB parser accepts empty, zero, and decimal values.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function testParseAdminGiBInput(): void
    {
        $service = $this->buildService(defaultQuotaBytes: 0, usedBytes: 0);

        self::assertSame(['quotaBytes' => null, 'errorKey' => null], $service->parseAdminGiBInput(''));
        self::assertSame(['quotaBytes' => 0, 'errorKey' => null], $service->parseAdminGiBInput('0'));
        self::assertSame(
            ['quotaBytes' => 2 * 1024 * 1024 * 1024, 'errorKey' => null],
            $service->parseAdminGiBInput('2')
        );
        self::assertSame(
            ['quotaBytes' => null, 'errorKey' => 'admin.users.error.invalid_storage_quota'],
            $service->parseAdminGiBInput('-1')
        );
    }

    /**
     * @brief Build quota service with mocked repositories.
     *
     * @param int $defaultQuotaBytes Platform default quota.
     * @param int $usedBytes Used bytes returned by repository.
     * @param UserRepository|null $userRepository Optional user repository mock.
     * @return UserStorageQuotaService
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function buildService(int $defaultQuotaBytes, int $usedBytes, ?UserRepository $userRepository = null): UserStorageQuotaService
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getSingleScalarResult')->willReturn($usedBytes);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $sharedFileRepository = $this->createMock(SharedFileRepository::class);
        $sharedFileRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        return new UserStorageQuotaService(
            $sharedFileRepository,
            $userRepository ?? $this->createMock(UserRepository::class),
            $defaultQuotaBytes,
        );
    }

    /**
     * @brief Set synthetic user id for tests.
     *
     * @param User $user Target user.
     * @param int $id Identifier value.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function setUserId(User $user, int $id): void
    {
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setAccessible(true);
        $property->setValue($user, $id);
    }
}
