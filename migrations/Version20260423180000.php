<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423180000 extends AbstractMigration
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
        return 'Add setup confirmation flag on users for admin bootstrap gate.';
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
        $this->addSql('ALTER TABLE app_user ADD setup_confirmed TINYINT(1) NOT NULL DEFAULT 0');
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
        $this->addSql('ALTER TABLE app_user DROP setup_confirmed');
    }
}
