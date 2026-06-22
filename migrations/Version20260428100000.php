<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428100000 extends AbstractMigration
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
        return 'Sprint 22: add shared_file.is_public and shared_file.public_expires_at to decouple public sharing from friends grants. Backfills from legacy visibility/expires_at, leaves the legacy columns in place for transition.';
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
        $this->addSql("ALTER TABLE shared_file ADD is_public TINYINT(1) NOT NULL DEFAULT 0");
        $this->addSql("ALTER TABLE shared_file ADD public_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("UPDATE shared_file SET is_public = 1 WHERE visibility = 'public'");
        $this->addSql("UPDATE shared_file SET public_expires_at = expires_at WHERE visibility = 'public' AND expires_at IS NOT NULL");
        $this->addSql('CREATE INDEX idx_shared_file_is_public ON shared_file (is_public)');
        $this->addSql('CREATE INDEX idx_shared_file_public_expires_at ON shared_file (public_expires_at)');
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
        $this->addSql('DROP INDEX idx_shared_file_public_expires_at ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_is_public ON shared_file');
        $this->addSql('ALTER TABLE shared_file DROP public_expires_at');
        $this->addSql('ALTER TABLE shared_file DROP is_public');
    }
}
