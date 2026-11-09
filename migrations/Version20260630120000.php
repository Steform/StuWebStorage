<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: add mobile files view mode preference column.
 */
final class Version20260630120000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add files_view_mode_mobile column to user_device_ui_preference (default grid).';
    }

    /**
     * @brief Apply schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_device_ui_preference ADD files_view_mode_mobile VARCHAR(8) NOT NULL DEFAULT 'grid'");
    }

    /**
     * @brief Revert schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-30
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_device_ui_preference DROP files_view_mode_mobile');
    }
}
