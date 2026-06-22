<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423150000 extends AbstractMigration
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
        return 'Create login_totp_challenge table for persistent login second-factor verification.';
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
        $this->addSql('CREATE TABLE login_totp_challenge (id INT AUTO_INCREMENT NOT NULL, identity VARCHAR(190) NOT NULL, code_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', consumed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_login_totp_identity (identity), INDEX idx_login_totp_expires (expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
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
        $this->addSql('DROP TABLE login_totp_challenge');
    }
}
