<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429161000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Persist folder-level share policies for future uploads.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE folder ADD is_public_share_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD public_share_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD friends_share_user_ids JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE folder DROP is_public_share_enabled, DROP public_share_expires_at, DROP friends_share_user_ids');
    }
}

