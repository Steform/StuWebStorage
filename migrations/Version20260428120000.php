<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428120000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Sprint 21: add user_deletion_snapshot vault table for encrypted hard-delete rollback payloads.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE user_deletion_snapshot (id INT AUTO_INCREMENT NOT NULL, target_user_id INT NOT NULL, ciphertext LONGTEXT NOT NULL, signature VARCHAR(255) NOT NULL, algo VARCHAR(32) NOT NULL, key_version VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', restored_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', purged_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', status VARCHAR(32) NOT NULL, INDEX idx_user_deletion_snapshot_target (target_user_id), INDEX idx_user_deletion_snapshot_status (status), INDEX idx_user_deletion_snapshot_created (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_deletion_snapshot');
    }
}
