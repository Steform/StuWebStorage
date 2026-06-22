<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427230000 extends AbstractMigration
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
        return 'Sprint 21: add nullable expires_at to share_grant for grant-level expiration semantics.';
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
        $this->addSql('ALTER TABLE share_grant ADD expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_share_grant_expires_at ON share_grant (expires_at)');
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
        $this->addSql('DROP INDEX idx_share_grant_expires_at ON share_grant');
        $this->addSql('ALTER TABLE share_grant DROP expires_at');
    }
}
