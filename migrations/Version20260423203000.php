<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user management and session invalidation columns on app_user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD active TINYINT(1) NOT NULL DEFAULT 1, ADD password_reset_required TINYINT(1) NOT NULL DEFAULT 0, ADD session_version INT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP active, DROP password_reset_required, DROP session_version');
    }
}
