<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Add homepage antibot gate enable flag to platform settings.
 */
final class Version20260623210000 extends AbstractMigration
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
        return 'Add antibot_gate_enabled column to site_access_gate_settings';
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

        if (!$table->hasColumn('antibot_gate_enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings ADD antibot_gate_enabled TINYINT(1) DEFAULT 1 NOT NULL');
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

        if ($table->hasColumn('antibot_gate_enabled')) {
            $this->addSql('ALTER TABLE site_access_gate_settings DROP antibot_gate_enabled');
        }
    }
}
