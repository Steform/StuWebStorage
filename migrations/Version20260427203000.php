<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427203000 extends AbstractMigration
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
        return 'Sprint 17: indexes on share_grant for owner listing joins and grantee filters.';
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
        $this->addSql('CREATE INDEX idx_share_grant_shared_file_id ON share_grant (shared_file_id)');
        $this->addSql('CREATE INDEX idx_share_grant_grantee_user_id ON share_grant (grantee_user_id)');
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
        $this->addSql('DROP INDEX idx_share_grant_grantee_user_id ON share_grant');
        $this->addSql('DROP INDEX idx_share_grant_shared_file_id ON share_grant');
    }
}
