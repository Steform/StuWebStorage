<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423120000 extends AbstractMigration
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
        return 'Add roles column for Symfony security user model.';
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
        $this->addSql('ALTER TABLE app_user ADD roles JSON NOT NULL');
        $this->addSql('UPDATE app_user SET roles = \'["ROLE_USER"]\'');
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
        $this->addSql('ALTER TABLE app_user DROP roles');
    }
}
