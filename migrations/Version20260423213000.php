<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create profile email change request table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profile_email_change_request (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, new_email VARCHAR(190) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', consumed TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_profile_email_change_active (user_id, consumed, expires_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE profile_email_change_request');
    }
}
