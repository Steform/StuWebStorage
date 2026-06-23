<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Drop public access lock columns and add homepage antibot threshold.
 */
final class Version20260623190000 extends AbstractMigration
{
    /**
     * @brief Return migration description.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Remove public access lock columns and add antibot_threshold to site_access_gate_settings';
    }

    /**
     * @brief Apply schema changes.
     *
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('site_access_gate_settings');

        if (!$table->hasColumn('antibot_threshold')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD antibot_threshold SMALLINT DEFAULT 50 NOT NULL');
        }

        if ($table->hasColumn('enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP enabled');
        }
        if ($table->hasColumn('gate_message')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP gate_message');
        }
        if ($table->hasColumn('bypass_note')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP bypass_note');
        }
    }

    /**
     * @brief Revert schema changes.
     *
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $table = $schema->getTable('site_access_gate_settings');

        if (!$table->hasColumn('enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD enabled TINYINT(1) DEFAULT 0 NOT NULL');
        }
        if (!$table->hasColumn('gate_message')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD gate_message LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('bypass_note')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD bypass_note VARCHAR(255) DEFAULT NULL');
        }

        if ($table->hasColumn('antibot_threshold')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP antibot_threshold');
        }
    }
}
