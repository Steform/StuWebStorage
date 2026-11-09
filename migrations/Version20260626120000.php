<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Folder-level friends grants and folder ancestor closure table.
 */
final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add folder_share_grant and folder_ancestor tables with initial backfill.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE folder_share_grant (
            id INT AUTO_INCREMENT NOT NULL,
            folder_id INT NOT NULL,
            grantee_user_id INT NOT NULL,
            expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_folder_share_grant_folder (folder_id),
            INDEX idx_folder_share_grant_grantee_expires (grantee_user_id, expires_at),
            UNIQUE INDEX uniq_folder_share_grant_folder_grantee (folder_id, grantee_user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE folder_ancestor (
            folder_id INT NOT NULL,
            ancestor_folder_id INT NOT NULL,
            depth INT NOT NULL,
            INDEX idx_folder_ancestor_ancestor_folder (ancestor_folder_id, folder_id),
            PRIMARY KEY(folder_id, ancestor_folder_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE folder_share_grant ADD CONSTRAINT FK_folder_share_grant_folder FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE CASCADE');

        $this->backfillFolderAncestors();
        $this->backfillFolderShareGrants();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE folder_share_grant DROP FOREIGN KEY FK_folder_share_grant_folder');
        $this->addSql('DROP TABLE folder_ancestor');
        $this->addSql('DROP TABLE folder_share_grant');
    }

    private function backfillFolderAncestors(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, parent_folder_id FROM folder ORDER BY id ASC'
        );
        if ($rows === []) {
            return;
        }

        $parentById = [];
        foreach ($rows as $row) {
            $folderId = (int) ($row['id'] ?? 0);
            if ($folderId < 1) {
                continue;
            }
            $parentRaw = $row['parent_folder_id'] ?? null;
            $parentById[$folderId] = $parentRaw !== null ? (int) $parentRaw : null;
        }

        $insertRows = [];
        foreach (array_keys($parentById) as $folderId) {
            $cursorId = $folderId;
            $depth = 0;
            $visited = [];
            while ($cursorId !== null && $cursorId > 0 && !isset($visited[$cursorId])) {
                $visited[$cursorId] = true;
                $insertRows[] = [
                    'folder_id' => $folderId,
                    'ancestor_folder_id' => $cursorId,
                    'depth' => $depth,
                ];
                $cursorId = $parentById[$cursorId] ?? null;
                ++$depth;
            }
        }

        foreach ($insertRows as $insertRow) {
            $this->addSql(
                'INSERT IGNORE INTO folder_ancestor (folder_id, ancestor_folder_id, depth) VALUES (:folder_id, :ancestor_folder_id, :depth)',
                $insertRow
            );
        }
    }

    private function backfillFolderShareGrants(): void
    {
        $folders = $this->connection->fetchAllAssociative(
            'SELECT id, friends_share_user_ids FROM folder WHERE friends_share_user_ids IS NOT NULL'
        );
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($folders as $folderRow) {
            $folderId = (int) ($folderRow['id'] ?? 0);
            if ($folderId < 1) {
                continue;
            }
            $raw = $folderRow['friends_share_user_ids'] ?? null;
            if (!is_string($raw) || trim($raw) === '' || trim($raw) === '[]' || trim($raw) === 'null') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || $decoded === []) {
                continue;
            }
            $granteeIds = [];
            foreach ($decoded as $granteeId) {
                $id = (int) $granteeId;
                if ($id > 0) {
                    $granteeIds[$id] = $id;
                }
            }
            foreach ($granteeIds as $granteeUserId) {
                $this->addSql(
                    'INSERT IGNORE INTO folder_share_grant (folder_id, grantee_user_id, expires_at, created_at) VALUES (:folder_id, :grantee_user_id, NULL, :created_at)',
                    [
                        'folder_id' => $folderId,
                        'grantee_user_id' => $granteeUserId,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }
}
