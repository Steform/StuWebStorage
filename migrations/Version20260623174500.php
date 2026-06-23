<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: site access gate singleton settings table.
 */
final class Version20260623174500 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Create site_access_gate_settings table for the public access gate.';
    }

    /**
     * @brief Apply schema changes.
     *
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('site_access_gate_settings')) {
            $this->addSql('CREATE TABLE site_access_gate_settings (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT(1) DEFAULT 0 NOT NULL, gate_message LONGTEXT DEFAULT NULL, bypass_note VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            return;
        }

        $table = $schema->getTable('site_access_gate_settings');

        if (!$table->hasColumn('gate_message')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD gate_message LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('bypass_note')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD bypass_note VARCHAR(255) DEFAULT NULL');
        }
        if ($table->hasColumn('antibot_threshold')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP antibot_threshold');
        }
        if ($table->hasColumn('maintenance_message')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP maintenance_message');
        }
        if ($table->hasColumn('maintenance_mode_enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP maintenance_mode_enabled');
        }
    }

    /**
     * @brief Revert schema changes.
     *
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('site_access_gate_settings')) {
            return;
        }

        $this->addSql('DROP TABLE site_access_gate_settings');
    }
}
