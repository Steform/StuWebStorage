<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Entity\FolderShareGrant;
use App\Entity\ShareGrant;
use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\FolderShareGrantRepository;
use App\Repository\ShareGrantRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service FriendsShareService.
 *
 * Sprint 22 (2026-04-28): owns every state transition of the friends sharing channel
 * (per-grantee grants, each carrying its own optional expiration). The public channel
 * is NEVER touched here; it lives in PublicShareService. Apply semantics support both
 * merge mode (default) and replace mode (full overwrite of the existing grant set).
 */
class FriendsShareService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly FolderShareGrantRepository $folderShareGrantRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @brief Apply a friends-channel intent (merge or replace) to a single shared file.
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @param array<int, array{user_id: int, expires_at: DateTimeImmutable|null}> $granteeIntents Per-grantee intent rows.
     * @param bool $replaceExisting Replace mode (true) or merge mode (false).
     * @return array{previous_grants: list<array{user_id: int, expires_at: ?string}>, current_grants: list<array{user_id: int, expires_at: ?string}>, grants_added: int, grants_updated: int, grants_removed: int}
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function applyFriendsIntent(SharedFile $sharedFile, array $granteeIntents, bool $replaceExisting): array
    {
        $sharedFileId = (int) $sharedFile->getId();
        $ownerId = $sharedFile->getOwnerUserId();

        $previousSnapshot = $this->snapshotGrants($sharedFileId);

        $granteeIntents = $this->normalizeGranteeIntents($granteeIntents, $ownerId);

        $existingGrants = $this->shareGrantRepository->findAllBySharedFile($sharedFileId);
        $existingByUserId = [];
        foreach ($existingGrants as $existing) {
            $existingByUserId[$existing->getGranteeUserId()] = $existing;
        }

        $report = ['grants_added' => 0, 'grants_updated' => 0, 'grants_removed' => 0];

        if ($replaceExisting) {
            $this->removeGrantsMissingFromReplaceIntent($existingGrants, $granteeIntents, $existingByUserId, $report);
        }

        foreach ($granteeIntents as $intent) {
            $userId = (int) $intent['user_id'];
            if (!isset($existingByUserId[$userId])) {
                $grant = new ShareGrant($sharedFileId, $userId, $intent['expires_at']);
                $this->entityManager->persist($grant);
                ++$report['grants_added'];
                continue;
            }
            $current = $existingByUserId[$userId];
            $currentExpiry = $current->getExpiresAt();
            $newExpiry = $intent['expires_at'];
            if ($currentExpiry?->format('c') !== $newExpiry?->format('c')) {
                $current->setExpiresAt($newExpiry);
                ++$report['grants_updated'];
            }
        }

        $sharedFile->touchUpdatedAt();
        $this->entityManager->flush();

        return [
            'previous_grants' => $previousSnapshot,
            'current_grants' => $this->snapshotGrants($sharedFileId),
            'grants_added' => $report['grants_added'],
            'grants_updated' => $report['grants_updated'],
            'grants_removed' => $report['grants_removed'],
        ];
    }

    /**
     * @brief Validate, deduplicate, and resolve grantee intent rows against active users.
     * @param array<int, array{user_id?: mixed, expires_at?: mixed}> $rawIntents Raw intent rows from request payload.
     * @param int $ownerUserId Owner identifier (excluded from the grantee list).
     * @return array<int, array{user_id: int, expires_at: DateTimeImmutable|null}>
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function normalizeGranteeIntents(array $rawIntents, int $ownerUserId): array
    {
        $byId = [];
        foreach ($rawIntents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0 || $userId === $ownerUserId) {
                continue;
            }
            $user = $this->userRepository->find($userId);
            if (!$user instanceof User) {
                continue;
            }

            $expiresRaw = $row['expires_at'] ?? null;
            $expiresAt = null;
            if ($expiresRaw instanceof DateTimeImmutable) {
                $expiresAt = $expiresRaw;
            } elseif (is_string($expiresRaw) && trim($expiresRaw) !== '') {
                try {
                    $expiresAt = new DateTimeImmutable(trim($expiresRaw));
                } catch (\Exception) {
                    $expiresAt = null;
                }
            }

            $byId[$userId] = ['user_id' => $userId, 'expires_at' => $expiresAt];
        }

        return array_values($byId);
    }

    /**
     * @brief List active friends for a shared file with their pseudo and per-grant expiration.
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @return list<array{user_id: int, pseudonym: string, email: string, expires_at: ?string}>
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function listActiveFriendsState(SharedFile $sharedFile): array
    {
        $sharedFileId = (int) $sharedFile->getId();
        $grants = $this->shareGrantRepository->findActiveBySharedFile($sharedFileId);
        if ($grants === []) {
            return [];
        }
        $userIds = array_map(static fn (ShareGrant $grant): int => $grant->getGranteeUserId(), $grants);
        $users = $this->userRepository->findByIdsOrdered($userIds);
        $pseudoByUserId = [];
        $emailByUserId = [];
        foreach ($users as $user) {
            $pseudoByUserId[(int) $user->getId()] = $user->getPseudonym();
            $emailByUserId[(int) $user->getId()] = $user->getEmail();
        }

        $rows = [];
        foreach ($grants as $grant) {
            $uid = $grant->getGranteeUserId();
            $rows[] = [
                'user_id' => $uid,
                'pseudonym' => $pseudoByUserId[$uid] ?? '',
                'email' => $emailByUserId[$uid] ?? '',
                'expires_at' => $grant->getExpiresAt()?->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @brief Whether at least one grant carries an explicit expiration instant (active or not).
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function hasAnyGrantExpiration(SharedFile $sharedFile): bool
    {
        $grants = $this->shareGrantRepository->findAllBySharedFile((int) $sharedFile->getId());
        foreach ($grants as $grant) {
            if ($grant->getExpiresAt() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Build a serializable snapshot of the friends grant set for audit/rollback.
     * @param int $sharedFileId Shared file identifier.
     * @return list<array{user_id: int, expires_at: ?string}>
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function snapshotGrants(int $sharedFileId): array
    {
        $grants = $this->shareGrantRepository->findAllBySharedFile($sharedFileId);
        $rows = [];
        foreach ($grants as $grant) {
            $rows[] = [
                'user_id' => $grant->getGranteeUserId(),
                'expires_at' => $grant->getExpiresAt()?->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @brief Apply a friends-channel intent (merge or replace) to one shared folder subtree root.
     * @param Folder $folder Target folder aggregate.
     * @param array<int, array{user_id?: mixed, expires_at?: mixed}> $granteeIntents Per-grantee intent rows.
     * @param bool $replaceExisting Replace mode (true) or merge mode (false).
     * @return array{previous_grants: list<array{user_id: int, expires_at: ?string}>, current_grants: list<array{user_id: int, expires_at: ?string}>, grants_added: int, grants_updated: int, grants_removed: int}
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function applyFolderFriendsIntent(Folder $folder, array $granteeIntents, bool $replaceExisting): array
    {
        $folderId = (int) ($folder->getId() ?? 0);
        if ($folderId < 1) {
            return [
                'previous_grants' => [],
                'current_grants' => [],
                'grants_added' => 0,
                'grants_updated' => 0,
                'grants_removed' => 0,
            ];
        }

        $ownerId = $folder->getOwnerUserId();
        $previousSnapshot = $this->snapshotFolderGrants($folderId);
        $granteeIntents = $this->normalizeGranteeIntents($granteeIntents, $ownerId);

        $existingGrants = $this->folderShareGrantRepository->findAllByFolder($folderId);
        $existingByUserId = [];
        foreach ($existingGrants as $existing) {
            $existingByUserId[$existing->getGranteeUserId()] = $existing;
        }

        $report = ['grants_added' => 0, 'grants_updated' => 0, 'grants_removed' => 0];

        if ($replaceExisting) {
            $this->removeGrantsMissingFromReplaceIntent($existingGrants, $granteeIntents, $existingByUserId, $report);
        }

        foreach ($granteeIntents as $intent) {
            $userId = (int) $intent['user_id'];
            if (!isset($existingByUserId[$userId])) {
                $grant = new FolderShareGrant($folderId, $userId, $intent['expires_at']);
                $this->entityManager->persist($grant);
                ++$report['grants_added'];
                continue;
            }
            $current = $existingByUserId[$userId];
            $currentExpiry = $current->getExpiresAt();
            $newExpiry = $intent['expires_at'];
            if ($currentExpiry?->format('c') !== $newExpiry?->format('c')) {
                $current->setExpiresAt($newExpiry);
                ++$report['grants_updated'];
            }
        }

        $folder->touchUpdatedAt();
        $this->entityManager->flush();

        $currentGrants = $this->snapshotFolderGrants($folderId);
        $folder->setFriendsShareUserIds(array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $currentGrants
        ))));
        $this->entityManager->flush();

        return [
            'previous_grants' => $previousSnapshot,
            'current_grants' => $currentGrants,
            'grants_added' => $report['grants_added'],
            'grants_updated' => $report['grants_updated'],
            'grants_removed' => $report['grants_removed'],
        ];
    }

    /**
     * @brief Build a serializable snapshot of folder grants for audit and UI sync.
     * @param int $folderId Folder identifier.
     * @return list<array{user_id: int, expires_at: ?string}>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function snapshotFolderGrants(int $folderId): array
    {
        $grants = $this->folderShareGrantRepository->findAllByFolder($folderId);
        $rows = [];
        foreach ($grants as $grant) {
            if (!$grant instanceof FolderShareGrant) {
                continue;
            }
            $rows[] = [
                'user_id' => $grant->getGranteeUserId(),
                'expires_at' => $grant->getExpiresAt()?->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @brief List active folder friends for one folder with pseudonym and expiration.
     * @param Folder $folder Target folder aggregate.
     * @return list<array{user_id: int, pseudonym: string, email: string, expires_at: ?string}>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function listActiveFolderFriendsState(Folder $folder): array
    {
        $folderId = (int) ($folder->getId() ?? 0);
        if ($folderId < 1) {
            return [];
        }

        $grants = $this->folderShareGrantRepository->findActiveByFolder($folderId);
        if ($grants === []) {
            return [];
        }

        $userIds = array_map(static fn (FolderShareGrant $grant): int => $grant->getGranteeUserId(), $grants);
        $users = $this->userRepository->findByIdsOrdered($userIds);
        $pseudoByUserId = [];
        $emailByUserId = [];
        foreach ($users as $user) {
            $pseudoByUserId[(int) $user->getId()] = $user->getPseudonym();
            $emailByUserId[(int) $user->getId()] = $user->getEmail();
        }

        $rows = [];
        foreach ($grants as $grant) {
            $uid = $grant->getGranteeUserId();
            $rows[] = [
                'user_id' => $uid,
                'pseudonym' => $pseudoByUserId[$uid] ?? '',
                'email' => $emailByUserId[$uid] ?? '',
                'expires_at' => $grant->getExpiresAt()?->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @brief Remove existing grants not present in a replace-mode intent set (keeps rows that will be upserted).
     * @param array<int, ShareGrant|FolderShareGrant> $existingGrants Existing grant entities.
     * @param array<int, array{user_id: int, expires_at: DateTimeImmutable|null}> $granteeIntents Normalized intent rows.
     * @param array<int, ShareGrant|FolderShareGrant> $existingByUserId Existing grants keyed by grantee user id (updated in place).
     * @param array{grants_added: int, grants_updated: int, grants_removed: int} $report Mutation counters (updated in place).
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function removeGrantsMissingFromReplaceIntent(
        array $existingGrants,
        array $granteeIntents,
        array &$existingByUserId,
        array &$report,
    ): void {
        $intentUserIds = [];
        foreach ($granteeIntents as $intent) {
            $userId = (int) ($intent['user_id'] ?? 0);
            if ($userId > 0) {
                $intentUserIds[$userId] = true;
            }
        }

        foreach ($existingGrants as $existing) {
            $granteeUserId = $existing->getGranteeUserId();
            if (isset($intentUserIds[$granteeUserId])) {
                continue;
            }
            $this->entityManager->remove($existing);
            unset($existingByUserId[$granteeUserId]);
            ++$report['grants_removed'];
        }
    }
}
