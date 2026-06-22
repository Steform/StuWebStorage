<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public share optional password: enabled flag, argon/bcrypt hash, encrypted plaintext for owner UI.
 */
final class Version20260504120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public_password_enabled, public_password_hash, public_password_secret to shared_file and folder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_file ADD public_password_enabled TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE shared_file ADD public_password_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE shared_file ADD public_password_secret LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE folder ADD public_password_enabled TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE folder ADD public_password_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE folder ADD public_password_secret LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_file DROP public_password_secret, DROP public_password_hash, DROP public_password_enabled');
        $this->addSql('ALTER TABLE folder DROP public_password_secret, DROP public_password_hash, DROP public_password_enabled');
    }
}
