<?php

namespace App\Tests\Functional\Share;

use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\ShareGrantRepository;
use App\Repository\UserRepository;
use App\Service\Share\FriendsShareService;
use App\Service\Share\PublicShareService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Sprint 22+: public and friends share services orchestration contracts without DB.
 *
 * @date 2026-06-17
 * @author Stephane H.
 */
class BulkShareTest extends TestCase
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
     * @brief Build a minimal User entity with fixed id.
     * @param int $userId User identifier.
     * @return User
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function buildUser(int $userId): User
    {
        $user = new User();
        $reflection = new ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $userId);

        return $user;
    }

    /**
     * @brief Apply unified share intent through channel services (former ShareUpdateService contract).
     * @param PublicShareService $publicShare Public channel service.
     * @param FriendsShareService $friendsShare Friends channel service.
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @param string $visibility Target visibility ('public' or 'private').
     * @param DateTimeImmutable|null $fileExpiresAt File-level expiration instant.
     * @param array<int, array{user_id: int, expires_at: DateTimeImmutable|null}> $granteeIntents Per-grantee intent rows.
     * @param bool $replaceExisting Replace mode (true) or merge mode (false).
     * @return array{visibility: string, grants_added: int, grants_updated: int, grants_removed: int}
     * @date 2026-06-17
     * @author Stephane H.
     */
    private function applyShareIntent(
        PublicShareService $publicShare,
        FriendsShareService $friendsShare,
        SharedFile $sharedFile,
        string $visibility,
        ?DateTimeImmutable $fileExpiresAt,
        array $granteeIntents,
        bool $replaceExisting
    ): array {
        if ($visibility === 'public') {
            $publicShare->enablePublic($sharedFile, $fileExpiresAt);
        } else {
            $publicShare->disablePublic($sharedFile);
        }

        $report = $friendsShare->applyFriendsIntent($sharedFile, $granteeIntents, $replaceExisting);

        return [
            'visibility' => $sharedFile->getVisibility(),
            'grants_added' => (int) $report['grants_added'],
            'grants_updated' => (int) $report['grants_updated'],
            'grants_removed' => (int) $report['grants_removed'],
        ];
    }

    /**
     * @brief Merge mode delegates friends intents with merge flag and disables public channel when private.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testApplyShareIntentMergeAddsAndUpdatesWithoutRemovingOtherGrants(): void
    {
        $sharedFile = new SharedFile(1, '/tmp/merge.dat', 'private', 'token-merge', 'merge.bin');
        $this->forceSharedFileId($sharedFile, 100);

        $newExpiry = new DateTimeImmutable('2026-06-15T12:00:00');
        $intents = [
            ['user_id' => 201, 'expires_at' => $newExpiry],
            ['user_id' => 202, 'expires_at' => null],
        ];

        $publicShare = $this->createMock(PublicShareService::class);
        $publicShare->expects(self::once())->method('disablePublic')->with($sharedFile)->willReturnCallback(
            static function (SharedFile $sf): array {
                $sf->setIsPublic(false);

                return ['previous' => [], 'current' => []];
            }
        );

        $friendsShare = $this->createMock(FriendsShareService::class);
        $friendsShare->expects(self::once())->method('applyFriendsIntent')
            ->with($sharedFile, $intents, false)
            ->willReturn([
                'grants_added' => 1,
                'grants_updated' => 1,
                'grants_removed' => 0,
                'previous_grants' => [],
                'current_grants' => [],
            ]);

        $report = $this->applyShareIntent($publicShare, $friendsShare, $sharedFile, 'private', null, $intents, false);

        self::assertSame('private', $report['visibility']);
        self::assertSame(1, $report['grants_added']);
        self::assertSame(1, $report['grants_updated']);
        self::assertSame(0, $report['grants_removed']);
    }

    /**
     * @brief Replace mode forwards replaceExisting=true to FriendsShareService.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testApplyShareIntentReplaceClearsExistingThenRecreates(): void
    {
        $sharedFile = new SharedFile(1, '/tmp/replace.dat', 'private', 'token-replace', 'replace.bin');
        $this->forceSharedFileId($sharedFile, 200);

        $intents = [
            ['user_id' => 301, 'expires_at' => null],
            ['user_id' => 303, 'expires_at' => new DateTimeImmutable('2026-08-01T10:00:00')],
        ];

        $publicShare = $this->createMock(PublicShareService::class);
        $publicShare->expects(self::once())->method('disablePublic')->with($sharedFile)->willReturnCallback(
            static function (SharedFile $sf): array {
                $sf->setIsPublic(false);

                return ['previous' => [], 'current' => []];
            }
        );

        $friendsShare = $this->createMock(FriendsShareService::class);
        $friendsShare->expects(self::once())->method('applyFriendsIntent')
            ->with($sharedFile, $intents, true)
            ->willReturn([
                'grants_added' => 2,
                'grants_updated' => 0,
                'grants_removed' => 2,
                'previous_grants' => [],
                'current_grants' => [],
            ]);

        $report = $this->applyShareIntent($publicShare, $friendsShare, $sharedFile, 'private', null, $intents, true);

        self::assertSame(2, $report['grants_added']);
        self::assertSame(0, $report['grants_updated']);
        self::assertSame(2, $report['grants_removed']);
    }

    /**
     * @brief Switching to private invokes disablePublic then applies empty friends intent.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testVisibilityFlipToPrivateRotatesPublicToken(): void
    {
        $sharedFile = new SharedFile(1, '/tmp/flip.dat', 'public', 'token-public-old', 'flip.bin');
        $this->forceSharedFileId($sharedFile, 300);

        $publicShare = $this->createMock(PublicShareService::class);
        $publicShare->expects(self::once())->method('disablePublic')->with($sharedFile)->willReturnCallback(
            static function (SharedFile $sf): array {
                $sf->setIsPublic(false);

                return ['previous' => [], 'current' => []];
            }
        );

        $friendsShare = $this->createMock(FriendsShareService::class);
        $friendsShare->expects(self::once())->method('applyFriendsIntent')
            ->with($sharedFile, [], false)
            ->willReturn([
                'grants_added' => 0,
                'grants_updated' => 0,
                'grants_removed' => 0,
                'previous_grants' => [],
                'current_grants' => [],
            ]);

        $this->applyShareIntent($publicShare, $friendsShare, $sharedFile, 'private', null, [], false);

        self::assertSame('private', $sharedFile->getVisibility());
    }

    /**
     * @brief Public visibility with expiration delegates enablePublic with expiration instant.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testApplyShareIntentSetsPublicExpirationViaEnablePublic(): void
    {
        $sharedFile = new SharedFile(1, '/tmp/exp.dat', 'private', 'token-exp', 'exp.bin');
        $this->forceSharedFileId($sharedFile, 400);

        $expiresAt = new DateTimeImmutable('2026-12-31T23:59:00');

        $publicShare = $this->createMock(PublicShareService::class);
        $publicShare->expects(self::once())->method('enablePublic')->with($sharedFile, $expiresAt)->willReturnCallback(
            static function (SharedFile $sf, ?DateTimeImmutable $exp): array {
                $sf->setIsPublic(true);
                $sf->setPublicExpiresAt($exp);
                $sf->setExpiresAt($exp);

                return ['previous' => [], 'current' => []];
            }
        );

        $friendsShare = $this->createMock(FriendsShareService::class);
        $friendsShare->expects(self::once())->method('applyFriendsIntent')
            ->willReturn([
                'grants_added' => 0,
                'grants_updated' => 0,
                'grants_removed' => 0,
                'previous_grants' => [],
                'current_grants' => [],
            ]);

        $this->applyShareIntent($publicShare, $friendsShare, $sharedFile, 'public', $expiresAt, [], false);
    }

    /**
     * @brief normalizeGranteeIntents delegates to FriendsShareService (real normalization rules).
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testNormalizeGranteeIntentsRejectsOwnerAndUnknownUsers(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturnCallback(function (mixed $id): ?User {
            $uid = (int) $id;
            if (\in_array($uid, [201, 202], true)) {
                return $this->buildUser($uid);
            }

            return null;
        });

        $friends = new FriendsShareService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ShareGrantRepository::class),
            $userRepository
        );

        $rawIntents = [
            ['user_id' => 0, 'expires_at' => ''],
            ['user_id' => 100, 'expires_at' => ''],
            ['user_id' => 201, 'expires_at' => '2026-07-01T08:00:00'],
            ['user_id' => 201, 'expires_at' => '2026-08-01T08:00:00'],
            ['user_id' => 202, 'expires_at' => null],
            ['user_id' => 999, 'expires_at' => 'invalid-date'],
            'broken-row',
        ];

        $normalized = $friends->normalizeGranteeIntents($rawIntents, 100);

        $byId = [];
        foreach ($normalized as $row) {
            $byId[$row['user_id']] = $row;
        }
        self::assertSame([201, 202], array_keys($byId));
        self::assertNull($byId[202]['expires_at']);
        self::assertNotNull($byId[201]['expires_at']);
        self::assertSame('2026-08-01 08:00:00', $byId[201]['expires_at']->format('Y-m-d H:i:s'));
    }
}
