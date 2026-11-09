<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Add content fingerprints on shared_file and create download_archive table.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class Version20261102190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content_crc32/content_sha256 on shared_file and create download_archive for virtual ZIP manifests.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_file ADD content_crc32 INT UNSIGNED DEFAULT NULL, ADD content_sha256 CHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_shared_file_content_sha256 ON shared_file (content_sha256)');

        $this->addSql('CREATE TABLE download_archive (
            id INT AUTO_INCREMENT NOT NULL,
            owner_user_id INT DEFAULT NULL,
            total_bytes BIGINT NOT NULL,
            prepared_bytes BIGINT NOT NULL DEFAULT 0,
            entry_count INT NOT NULL DEFAULT 0,
            skipped_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            ready_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            last_access_at DATETIME DEFAULT NULL,
            preparing_until DATETIME DEFAULT NULL,
            token VARCHAR(64) NOT NULL,
            kind VARCHAR(32) NOT NULL,
            actor_type VARCHAR(32) NOT NULL,
            actor_id VARCHAR(128) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            status VARCHAR(32) NOT NULL,
            layout_version INT NOT NULL DEFAULT 1,
            etag VARCHAR(128) DEFAULT NULL,
            error_code VARCHAR(128) DEFAULT NULL,
            entries_json JSON NOT NULL,
            UNIQUE INDEX uniq_download_archive_token (token),
            INDEX idx_download_archive_actor (actor_type, actor_id),
            INDEX idx_download_archive_status_expires (status, expires_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE download_archive');
        $this->addSql('DROP INDEX idx_shared_file_content_sha256 ON shared_file');
        $this->addSql('ALTER TABLE shared_file DROP content_crc32, DROP content_sha256');
    }
}
