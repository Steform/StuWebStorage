<?php

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service UserHardDeletePurgeService.
 */
class UserHardDeletePurgeService
{
    /**
     * @brief Build hard delete purge executor service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @brief Purge all functional rows and files linked to target user (including folder public tokens and owned folders).
     * @param array<string, mixed> $snapshotPayload Snapshot payload.
     * @return array<string, int>
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function purgeFromSnapshot(array $snapshotPayload): array
    {
        $userId = (int) ($snapshotPayload['targetUserId'] ?? 0);
        $ownedFileRows = is_array($snapshotPayload['sharedFilesOwned'] ?? null) ? $snapshotPayload['sharedFilesOwned'] : [];
        $ownedFileIds = array_values(array_filter(array_map(
            static fn (mixed $row): int => is_array($row) ? (int) ($row['id'] ?? 0) : 0,
            $ownedFileRows
        ), static fn (int $id): bool => $id > 0));

        $tokens = array_values(array_filter(array_map(
            static fn (mixed $row): string => is_array($row) ? (string) ($row['public_token'] ?? '') : '',
            $ownedFileRows
        ), static fn (string $token): bool => $token !== ''));

        $connection = $this->entityManager->getConnection();

        return $this->entityManager->wrapInTransaction(function () use ($connection, $userId, $ownedFileIds, $tokens, $ownedFileRows): array {
            $folderPublicTokens = $connection->fetchFirstColumn(
                'SELECT public_folder_token FROM folder WHERE owner_user_id = :uid AND public_folder_token IS NOT NULL',
                ['uid' => $userId]
            );
            foreach ($folderPublicTokens as $row) {
                $t = is_string($row) ? $row : '';
                if ($t !== '') {
                    $tokens[] = $t;
                }
            }
            $tokens = array_values(array_unique($tokens));
            $counts = [
                'trustedDevices' => $connection->executeStatement('DELETE FROM trusted_device WHERE user_id = :uid', ['uid' => $userId]),
                'passwordResetRequests' => $connection->executeStatement('DELETE FROM password_reset_request WHERE user_id = :uid', ['uid' => $userId]),
                'profileEmailChangeRequests' => $connection->executeStatement('DELETE FROM profile_email_change_request WHERE user_id = :uid', ['uid' => $userId]),
                'invitationTokensAsInvitee' => $connection->executeStatement('DELETE FROM user_invitation_token WHERE user_id = :uid', ['uid' => $userId]),
                'invitationTokensAsInviter' => $connection->executeStatement('DELETE FROM user_invitation_token WHERE invited_by_user_id = :uid', ['uid' => $userId]),
                'shareGrantsAsGrantee' => $connection->executeStatement('DELETE FROM share_grant WHERE grantee_user_id = :uid', ['uid' => $userId]),
                'folderShareGrantsAsGrantee' => $connection->executeStatement('DELETE FROM folder_share_grant WHERE grantee_user_id = :uid', ['uid' => $userId]),
            ];

            $userEmail = is_array($snapshotPayload['user'] ?? null)
                ? strtolower(trim((string) ($snapshotPayload['user']['email'] ?? '')))
                : '';
            if ($userEmail !== '') {
                $counts['loginTotpChallenges'] = $connection->executeStatement(
                    'DELETE FROM login_totp_challenge WHERE identity = :email',
                    ['email' => $userEmail]
                );
            } else {
                $counts['loginTotpChallenges'] = 0;
            }

            if ($tokens !== []) {
                $counts['publicChallenges'] = $connection->createQueryBuilder()
                    ->delete('public_download_challenge')
                    ->where('public_token IN (:tokens)')
                    ->setParameter('tokens', $tokens, Connection::PARAM_STR_ARRAY)
                    ->executeStatement();
            } else {
                $counts['publicChallenges'] = 0;
            }

            if ($ownedFileIds !== []) {
                $counts['shareGrantsForOwnedFiles'] = $connection->createQueryBuilder()
                    ->delete('share_grant')
                    ->where('shared_file_id IN (:ids)')
                    ->setParameter('ids', $ownedFileIds, Connection::PARAM_INT_ARRAY)
                    ->executeStatement();

                $counts['sharedFilesOwned'] = $connection->createQueryBuilder()
                    ->delete('shared_file')
                    ->where('id IN (:ids)')
                    ->setParameter('ids', $ownedFileIds, Connection::PARAM_INT_ARRAY)
                    ->executeStatement();
            } else {
                $counts['shareGrantsForOwnedFiles'] = 0;
                $counts['sharedFilesOwned'] = 0;
            }

            $counts['foldersOwned'] = $connection->executeStatement(
                'DELETE FROM folder WHERE owner_user_id = :uid',
                ['uid' => $userId]
            );
            $counts['folderShareGrantsForOwnedFolders'] = $connection->executeStatement(
                'DELETE fsg FROM folder_share_grant fsg INNER JOIN folder f ON f.id = fsg.folder_id WHERE f.owner_user_id = :uid',
                ['uid' => $userId]
            );

            $counts['user'] = $connection->executeStatement('DELETE FROM app_user WHERE id = :uid', ['uid' => $userId]);
            $this->purgeOwnedFileBlobs($ownedFileRows);

            return $counts;
        });
    }

    /**
     * @param array<int, mixed> $ownedFileRows
     * @return void
     */
    private function purgeOwnedFileBlobs(array $ownedFileRows): void
    {
        foreach ($ownedFileRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = (string) ($row['storage_path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
