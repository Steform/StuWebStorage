<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Create download diagnostic event table for production troubleshooting.
 *
 * @date 2026-07-07
 * @author Stephane H.
 */
final class Version20260707133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create download_diagnostic_event table with indexes for timeline lookup and filtering.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE download_diagnostic_event (id INT AUTO_INCREMENT NOT NULL, owner_user_id INT DEFAULT NULL, shared_file_id INT DEFAULT NULL, duration_ms INT DEFAULT NULL, http_status INT DEFAULT NULL, created_at DATETIME NOT NULL, download_id VARCHAR(64) NOT NULL, phase VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, actor_type VARCHAR(32) DEFAULT NULL, actor_identity_hash VARCHAR(128) DEFAULT NULL, ip_hash VARCHAR(128) DEFAULT NULL, user_agent_hash VARCHAR(128) DEFAULT NULL, bytes_total BIGINT DEFAULT NULL, bytes_sent BIGINT DEFAULT NULL, error_code VARCHAR(128) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, extra_json JSON DEFAULT NULL, INDEX idx_dde_download_id (download_id), INDEX idx_dde_created_at (created_at), INDEX idx_dde_phase_status (phase, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE download_diagnostic_event');
    }
}
