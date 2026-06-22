<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423170000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Create user_invitation_token table for first-login invitation activation.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_invitation_token (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, invited_by_user_id INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', consumed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8F40A56E81A90D8E (token_hash), INDEX idx_invitation_user (user_id), INDEX idx_invitation_expires (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_invitation_token');
    }
}
