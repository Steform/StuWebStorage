<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423194000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on trusted device user and fingerprint.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_trusted_device_user_fingerprint ON trusted_device (user_id, device_fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_trusted_device_user_fingerprint ON trusted_device');
    }
}
