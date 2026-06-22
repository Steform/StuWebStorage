<?php

namespace App\Tests\Functional\Share;

use App\Entity\ShareGrant;
use App\Entity\SharedFile;
use App\Repository\ShareGrantRepository;
use App\Service\Share\ShareAuthorizationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Dual-level expiration regression coverage (Sprint 21+): public channel vs friends channel (Sprint 22).
 *
 * @date 2026-05-02
 * @author Stephane H.
 */
class ExpirationSemanticsTest extends TestCase
{
    /**
     * @brief Force a fixed identifier on a SharedFile aggregate via reflection.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @param int $id Numeric identifier.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function forceSharedFileId(SharedFile $sharedFile, int $id): void
    {
        $reflection = new ReflectionProperty(SharedFile::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($sharedFile, $id);
    }

    /**
     * @brief Build a private file with optional file-level expiration.
     * @param int $ownerId Owner identifier.
     * @param DateTimeImmutable|null $expiresAt File-level expiration.
     * @return SharedFile
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function buildPrivateFile(int $ownerId, ?DateTimeImmutable $expiresAt): SharedFile
    {
        return new SharedFile(
            $ownerId,
            '/tmp/expiration-test.dat',
            'private',
            'token-private',
            'sample.txt',
            10,
            new DateTimeImmutable('-1 day'),
            $expiresAt
        );
    }

    /**
     * @brief Build a public file with optional file-level expiration.
     * @param int $ownerId Owner identifier.
     * @param DateTimeImmutable|null $expiresAt File-level expiration.
     * @return SharedFile
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function buildPublicFile(int $ownerId, ?DateTimeImmutable $expiresAt): SharedFile
    {
        return new SharedFile(
            $ownerId,
            '/tmp/expiration-public.dat',
            'public',
            'token-public',
            'sample.bin',
            10,
            new DateTimeImmutable('-1 day'),
            $expiresAt
        );
    }

    /**
     * @brief A non-expired file is considered public and still grantee-accessible.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testFileNotExpiredAllowsPublicAndGrants(): void
    {
        $publicFile = $this->buildPublicFile(1, null);
        $privateFile = $this->buildPrivateFile(1, null);
        $shareGrantRepository = $this->createMock(ShareGrantRepository::class);
        $service = new ShareAuthorizationService($shareGrantRepository);

        self::assertTrue($service->isPublic($publicFile));
        self::assertFalse($service->isFileExpired($publicFile));
        self::assertFalse($service->isFileExpired($privateFile));
    }

    /**
     * @brief Expired public channel hides listing; friends access follows DB grant activity; owner always allowed.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testExpiredFileCutsPublicAndGrantsButPreservesOwner(): void
    {
        $expired = new DateTimeImmutable('-2 hours');
        $ownerId = 1;
        $granteeId = 42;
        $sharedFileId = 501;

        $publicFile = $this->buildPublicFile($ownerId, $expired);
        $privateFile = $this->buildPrivateFile($ownerId, $expired);
        $this->forceSharedFileId($privateFile, $sharedFileId);

        $shareGrantRepository = $this->createMock(ShareGrantRepository::class);
        $shareGrantRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($sharedFileId, $granteeId): ?ShareGrant {
                if ((int) ($criteria['sharedFileId'] ?? 0) === $sharedFileId
                    && (int) ($criteria['granteeUserId'] ?? 0) === $granteeId) {
                    return new ShareGrant($sharedFileId, $granteeId);
                }

                return null;
            }
        );
        $shareGrantRepository->method('isFriendsGrantActiveAtDatabaseNow')->willReturnCallback(
            static function (int $sid, int $gid) use ($sharedFileId, $granteeId): bool {
                if ($sid === $sharedFileId && $gid === $granteeId) {
                    return false;
                }

                return false;
            }
        );
        $service = new ShareAuthorizationService($shareGrantRepository);

        self::assertTrue($service->isFileExpired($publicFile));
        self::assertFalse($service->isPublic($publicFile));
        self::assertFalse($service->canAccessPrivateByUser($privateFile, $granteeId, true));
        self::assertTrue($service->canAccessPrivateByUser($privateFile, $ownerId, false));
    }

    /**
     * @brief Expired ShareGrant cuts that grantee only; sibling grants remain active and file stays online.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testExpiredGrantCutsOnlyThatGrantee(): void
    {
        $ownerId = 1;
        $sharedFileId = 99;
        $expiredGranteeId = 42;
        $activeGranteeId = 43;

        $privateFile = $this->buildPrivateFile($ownerId, null);
        $this->forceSharedFileId($privateFile, $sharedFileId);

        $expiredGrant = new ShareGrant($sharedFileId, $expiredGranteeId, new DateTimeImmutable('-1 hour'));
        $activeGrant = new ShareGrant($sharedFileId, $activeGranteeId, new DateTimeImmutable('+2 hours'));

        $shareGrantRepository = $this->createMock(ShareGrantRepository::class);
        $shareGrantRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($sharedFileId, $expiredGranteeId, $activeGranteeId, $expiredGrant, $activeGrant): ?ShareGrant {
                $sid = (int) ($criteria['sharedFileId'] ?? 0);
                $uid = (int) ($criteria['granteeUserId'] ?? 0);
                if ($sid !== $sharedFileId) {
                    return null;
                }
                if ($uid === $expiredGranteeId) {
                    return $expiredGrant;
                }
                if ($uid === $activeGranteeId) {
                    return $activeGrant;
                }

                return null;
            }
        );
        $shareGrantRepository->method('isFriendsGrantActiveAtDatabaseNow')->willReturnCallback(
            static function (int $sid, int $gid) use ($sharedFileId, $expiredGranteeId, $activeGranteeId): bool {
                if ($sid !== $sharedFileId) {
                    return false;
                }
                if ($gid === $expiredGranteeId) {
                    return false;
                }
                if ($gid === $activeGranteeId) {
                    return true;
                }

                return false;
            }
        );
        $service = new ShareAuthorizationService($shareGrantRepository);

        self::assertTrue($service->isGrantExpired($expiredGrant));
        self::assertFalse($service->isGrantExpired($activeGrant));

        self::assertFalse($service->canAccessPrivateByUser($privateFile, $expiredGranteeId, true));
        self::assertTrue($service->canAccessPrivateByUser($privateFile, $activeGranteeId, true));
    }

    /**
     * @brief filterActiveGranteeIds drops expired grants and keeps every active grantee identifier.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilterActiveGranteeIdsKeepsOnlyActiveGrants(): void
    {
        $sharedFileId = 12;
        $expiredGranteeId = 100;
        $activeGranteeId = 101;
        $missingGranteeId = 102;

        $shareGrantRepository = $this->createMock(ShareGrantRepository::class);
        $shareGrantRepository->method('findActiveGranteeIdsBySharedFile')->with($sharedFileId)->willReturn([$activeGranteeId]);

        $service = new ShareAuthorizationService($shareGrantRepository);

        $kept = $service->filterActiveGranteeIds($sharedFileId, [$expiredGranteeId, $activeGranteeId, $missingGranteeId]);

        self::assertSame([$activeGranteeId], $kept);
    }

    /**
     * @brief ShareAuthorizationService holds no SharedFile remove/flush dependency: only a ShareGrantRepository.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testAuthorizationServiceDoesNotDependOnEntityManager(): void
    {
        $reflection = new \ReflectionClass(ShareAuthorizationService::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        self::assertCount(1, $parameters);
        $type = $parameters[0]->getType();
        self::assertNotNull($type);
        self::assertSame(ShareGrantRepository::class, $type->getName());
    }
}
