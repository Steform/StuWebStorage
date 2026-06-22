<?php

namespace App\Service\Admin;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service UserHardDeleteSnapshotService.
 */
class UserHardDeleteSnapshotService
{
    /**
     * @brief Build hard delete snapshot collection service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @brief Build full restorable payload for target user hard delete.
     * @param User $targetUser Target user aggregate.
     * @return array<string, mixed>
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function buildSnapshotPayload(User $targetUser): array
    {
        $userId = (int) $targetUser->getId();
        $connection = $this->entityManager->getConnection();
        $ownedSharedFiles = $connection->fetchAllAssociative('SELECT * FROM shared_file WHERE owner_user_id = :uid ORDER BY id ASC', ['uid' => $userId]);
        $ownedSharedFileIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $ownedSharedFiles);
        $grantsForOwnedFiles = [];
        if ($ownedSharedFileIds !== []) {
            $grantsForOwnedFiles = $connection->createQueryBuilder()
                ->select('*')
                ->from('share_grant')
                ->where('shared_file_id IN (:ids)')
                ->setParameter('ids', $ownedSharedFileIds, Connection::PARAM_INT_ARRAY)
                ->orderBy('id', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        }

        $tokenRows = array_map(
            static fn (array $row): string => (string) ($row['public_token'] ?? ''),
            $ownedSharedFiles
        );
        $tokenRows = array_values(array_filter($tokenRows, static fn (string $token): bool => $token !== ''));

        $publicChallenges = [];
        if ($tokenRows !== []) {
            $publicChallenges = $connection->createQueryBuilder()
                ->select('*')
                ->from('public_download_challenge')
                ->where('public_token IN (:tokens)')
                ->setParameter('tokens', $tokenRows, Connection::PARAM_STR_ARRAY)
                ->orderBy('id', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        }

        return [
            'snapshotCreatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            'targetUserId' => $userId,
            'user' => $connection->fetchAssociative('SELECT * FROM app_user WHERE id = :uid LIMIT 1', ['uid' => $userId]) ?: null,
            'trustedDevices' => $connection->fetchAllAssociative('SELECT * FROM trusted_device WHERE user_id = :uid ORDER BY id ASC', ['uid' => $userId]),
            'passwordResetRequests' => $connection->fetchAllAssociative('SELECT * FROM password_reset_request WHERE user_id = :uid ORDER BY id ASC', ['uid' => $userId]),
            'profileEmailChangeRequests' => $connection->fetchAllAssociative('SELECT * FROM profile_email_change_request WHERE user_id = :uid ORDER BY id ASC', ['uid' => $userId]),
            'invitationTokensAsInvitee' => $connection->fetchAllAssociative('SELECT * FROM user_invitation_token WHERE user_id = :uid ORDER BY id ASC', ['uid' => $userId]),
            'invitationTokensAsInviter' => $connection->fetchAllAssociative('SELECT * FROM user_invitation_token WHERE invited_by_user_id = :uid ORDER BY id ASC', ['uid' => $userId]),
            'sharedFilesOwned' => $ownedSharedFiles,
            'shareGrantsForOwnedFiles' => $grantsForOwnedFiles,
            'shareGrantsAsGrantee' => $connection->fetchAllAssociative('SELECT * FROM share_grant WHERE grantee_user_id = :uid ORDER BY id ASC', ['uid' => $userId]),
            'publicDownloadChallengesForOwnedTokens' => $publicChallenges,
            'fileBlobs' => $this->collectFileBlobs($ownedSharedFiles),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $ownedSharedFiles
     * @return array<int, array<string, string>>
     */
    private function collectFileBlobs(array $ownedSharedFiles): array
    {
        $blobs = [];
        foreach ($ownedSharedFiles as $row) {
            $path = (string) ($row['storage_path'] ?? '');
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $content = @file_get_contents($path);
            if (!is_string($content)) {
                continue;
            }
            $blobs[] = [
                'storagePath' => $path,
                'contentBase64' => base64_encode($content),
            ];
        }

        return $blobs;
    }
}
