<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: persist files listing sort preference per user (synced across devices).
 */
final class Version20260625120000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add files_sort_field and files_sort_direction to user_device_ui_preference.';
    }

    /**
     * @brief Apply schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_device_ui_preference ADD files_sort_field VARCHAR(32) NOT NULL DEFAULT 'name', ADD files_sort_direction VARCHAR(4) NOT NULL DEFAULT 'asc'");
    }

    /**
     * @brief Revert schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_device_ui_preference DROP files_sort_field, DROP files_sort_direction');
    }
}
