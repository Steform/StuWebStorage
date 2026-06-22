<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422130000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Create Sprint 0 authentication tables.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, pseudonym VARCHAR(100) NOT NULL, totp_enabled TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');

        $this->addSql('CREATE TABLE trusted_device (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, device_fingerprint VARCHAR(255) NOT NULL, trusted_until DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE password_reset_request (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, consumed TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7CE748AA5F37A13B ON password_reset_request (token)');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_request');
        $this->addSql('DROP TABLE trusted_device');
        $this->addSql('DROP TABLE app_user');
    }
}
