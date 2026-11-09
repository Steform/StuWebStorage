<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: optional bug report screenshot metadata.
 */
final class Version20260626130000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add optional screenshot metadata columns to bug_report.';
    }

    /**
     * @brief Apply schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('bug_report')) {
            return;
        }

        $table = $schema->getTable('bug_report');
        if (!$table->hasColumn('screenshot_path')) {
            $this->addSql('ALTER TABLE bug_report ADD screenshot_path VARCHAR(512) DEFAULT NULL');
        }
        if (!$table->hasColumn('screenshot_mime')) {
            $this->addSql('ALTER TABLE bug_report ADD screenshot_mime VARCHAR(32) DEFAULT NULL');
        }
        if (!$table->hasColumn('screenshot_byte_size')) {
            $this->addSql('ALTER TABLE bug_report ADD screenshot_byte_size INT DEFAULT NULL');
        }
    }

    /**
     * @brief Revert schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('bug_report')) {
            return;
        }

        $table = $schema->getTable('bug_report');
        if ($table->hasColumn('screenshot_byte_size')) {
            $this->addSql('ALTER TABLE bug_report DROP screenshot_byte_size');
        }
        if ($table->hasColumn('screenshot_mime')) {
            $this->addSql('ALTER TABLE bug_report DROP screenshot_mime');
        }
        if ($table->hasColumn('screenshot_path')) {
            $this->addSql('ALTER TABLE bug_report DROP screenshot_path');
        }
    }
}
