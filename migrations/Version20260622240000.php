<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: maintenance mode fields on platform settings.
 */
final class Version20260622240000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add maintenance mode columns to site_access_gate_settings.';
    }

    /**
     * @brief Apply schema changes.
     *
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('site_access_gate_settings')) {
            return;
        }

        $table = $schema->getTable('site_access_gate_settings');
        if (!$table->hasColumn('maintenance_mode_enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD maintenance_mode_enabled TINYINT(1) DEFAULT 0 NOT NULL');
        }
        if (!$table->hasColumn('maintenance_message')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD maintenance_message LONGTEXT DEFAULT NULL');
        }
    }

    /**
     * @brief Revert schema changes.
     *
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('site_access_gate_settings')) {
            return;
        }

        $table = $schema->getTable('site_access_gate_settings');
        if ($table->hasColumn('maintenance_message')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP maintenance_message');
        }
        if ($table->hasColumn('maintenance_mode_enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP maintenance_mode_enabled');
        }
    }
}
