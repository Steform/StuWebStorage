<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427140000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Sprint 15: shared_file metadata, expiration, and index for lazy purge.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shared_file ADD original_file_name VARCHAR(255) NOT NULL DEFAULT 'file', ADD byte_size BIGINT NOT NULL DEFAULT 0, ADD uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '(DC2Type:datetime_immutable)', ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_shared_file_expires_at ON shared_file (expires_at)');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_shared_file_expires_at ON shared_file');
        $this->addSql('ALTER TABLE shared_file DROP original_file_name, DROP byte_size, DROP uploaded_at, DROP expires_at');
    }
}
