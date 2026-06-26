<?php

namespace App\Service\Admin;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service UserHardDeleteRestoreService.
 */
class UserHardDeleteRestoreService
{
    /**
     * @brief Build hard delete restore executor service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @brief Restore deleted user and linked records from snapshot payload.
     * @param array<string, mixed> $snapshotPayload Snapshot payload.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function restoreFromSnapshot(array $snapshotPayload): void
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->wrapInTransaction(function () use ($connection, $snapshotPayload): void {
            $this->upsertRows($connection, 'app_user', $snapshotPayload['user'] ?? null);
            $this->upsertRows($connection, 'trusted_device', $snapshotPayload['trustedDevices'] ?? []);
            $this->upsertRows($connection, 'password_reset_request', $snapshotPayload['passwordResetRequests'] ?? []);
            $this->upsertRows($connection, 'profile_email_change_request', $snapshotPayload['profileEmailChangeRequests'] ?? []);
            $this->upsertRows($connection, 'user_invitation_token', $snapshotPayload['invitationTokensAsInvitee'] ?? []);
            $this->upsertRows($connection, 'user_invitation_token', $snapshotPayload['invitationTokensAsInviter'] ?? []);
            $this->upsertRows($connection, 'shared_file', $snapshotPayload['sharedFilesOwned'] ?? []);
            $this->upsertRows($connection, 'share_grant', $snapshotPayload['shareGrantsForOwnedFiles'] ?? []);
            $this->upsertRows($connection, 'share_grant', $snapshotPayload['shareGrantsAsGrantee'] ?? []);
            $this->upsertRows($connection, 'folder_share_grant', $snapshotPayload['folderShareGrantsForOwnedFolders'] ?? []);
            $this->upsertRows($connection, 'folder_share_grant', $snapshotPayload['folderShareGrantsAsGrantee'] ?? []);
            $this->upsertRows($connection, 'public_download_challenge', $snapshotPayload['publicDownloadChallengesForOwnedTokens'] ?? []);
            $this->restoreFileBlobs($snapshotPayload['fileBlobs'] ?? []);
        });
    }

    /**
     * @param Connection $connection
     * @param string $tableName
     * @param mixed $rows
     * @return void
     */
    private function upsertRows(Connection $connection, string $tableName, mixed $rows): void
    {
        if ($rows === null) {
            return;
        }
        if (is_array($rows) && isset($rows['id'])) {
            $rows = [$rows];
        }
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $id = (int) $row['id'];
            if ($id <= 0) {
                continue;
            }

            $existing = $connection->fetchOne('SELECT id FROM '.$tableName.' WHERE id = :id LIMIT 1', ['id' => $id]);
            if ($existing !== false && $existing !== null) {
                continue;
            }

            $columns = [];
            $values = [];
            $params = [];
            foreach ($row as $column => $value) {
                $columns[] = (string) $column;
                $values[] = ':'.$column;
                $params[(string) $column] = $this->normalizeValue($value);
            }
            if ($columns === []) {
                continue;
            }

            $connection->executeStatement(
                'INSERT INTO '.$tableName.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).')',
                $params
            );
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && preg_match('/^\d{4}-\d{2}-\d{2} /', $value) === 1) {
            try {
                return new DateTimeImmutable($value);
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @param mixed $fileBlobs
     * @return void
     */
    private function restoreFileBlobs(mixed $fileBlobs): void
    {
        if (!is_array($fileBlobs)) {
            return;
        }

        foreach ($fileBlobs as $blob) {
            if (!is_array($blob)) {
                continue;
            }
            $path = (string) ($blob['storagePath'] ?? '');
            $content = (string) ($blob['contentBase64'] ?? '');
            if ($path === '' || $content === '') {
                continue;
            }

            $decoded = base64_decode($content, true);
            if (!is_string($decoded)) {
                continue;
            }

            $directory = dirname($path);
            if ($directory !== '' && !is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }
            @file_put_contents($path, $decoded);
        }
    }
}
