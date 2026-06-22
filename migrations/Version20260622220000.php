<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: persist user bug report tickets.
 */
final class Version20260622220000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Create bug_report table for user bug ticketing workflow.';
    }

    /**
     * @brief Apply schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('bug_report')) {
            $this->addMissingBugReportIndexes($schema);

            return;
        }

        $this->addSql("CREATE TABLE bug_report (id INT AUTO_INCREMENT NOT NULL, reporter_user_id INT NOT NULL, resolved_by_user_id INT DEFAULT NULL, archived_by_user_id INT DEFAULT NULL, status VARCHAR(32) NOT NULL, severity VARCHAR(32) NOT NULL, action_description LONGTEXT NOT NULL, observed_result LONGTEXT NOT NULL, expected_result LONGTEXT DEFAULT NULL, route_name VARCHAR(255) DEFAULT NULL, path VARCHAR(2048) NOT NULL, query_string LONGTEXT DEFAULT NULL, locale VARCHAR(10) NOT NULL, theme VARCHAR(10) NOT NULL, user_agent LONGTEXT DEFAULT NULL, viewport_width INT DEFAULT NULL, viewport_height INT DEFAULT NULL, referrer LONGTEXT DEFAULT NULL, correlation_id VARCHAR(128) DEFAULT NULL, app_version VARCHAR(64) DEFAULT NULL, action_timeline_json JSON DEFAULT NULL, resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', archived_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', archive_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_bug_report_status (status), INDEX idx_bug_report_severity (severity), INDEX idx_bug_report_route_name (route_name), INDEX idx_bug_report_created_at (created_at), INDEX idx_bug_report_archived_at (archived_at), INDEX IDX_B7A37DC77D07B173 (reporter_user_id), INDEX IDX_B7A37DC7356B4A34 (resolved_by_user_id), INDEX IDX_B7A37DC7688A3B2B (archived_by_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE bug_report ADD CONSTRAINT FK_B7A37DC77D07B173 FOREIGN KEY (reporter_user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bug_report ADD CONSTRAINT FK_B7A37DC7356B4A34 FOREIGN KEY (resolved_by_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bug_report ADD CONSTRAINT FK_B7A37DC7688A3B2B FOREIGN KEY (archived_by_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    /**
     * @brief Revert schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('bug_report')) {
            return;
        }

        $this->addSql('ALTER TABLE bug_report DROP FOREIGN KEY FK_B7A37DC77D07B173');
        $this->addSql('ALTER TABLE bug_report DROP FOREIGN KEY FK_B7A37DC7356B4A34');
        $this->addSql('ALTER TABLE bug_report DROP FOREIGN KEY FK_B7A37DC7688A3B2B');
        $this->addSql('DROP TABLE bug_report');
    }

    /**
     * @brief Add entity indexes when bug_report already exists from a removed duplicate migration.
     * @param Schema $schema Current database schema.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function addMissingBugReportIndexes(Schema $schema): void
    {
        $table = $schema->getTable('bug_report');
        $indexes = [
            'idx_bug_report_status' => 'CREATE INDEX idx_bug_report_status ON bug_report (status)',
            'idx_bug_report_severity' => 'CREATE INDEX idx_bug_report_severity ON bug_report (severity)',
            'idx_bug_report_route_name' => 'CREATE INDEX idx_bug_report_route_name ON bug_report (route_name)',
            'idx_bug_report_created_at' => 'CREATE INDEX idx_bug_report_created_at ON bug_report (created_at)',
            'idx_bug_report_archived_at' => 'CREATE INDEX idx_bug_report_archived_at ON bug_report (archived_at)',
        ];

        foreach ($indexes as $name => $sql) {
            if (!$table->hasIndex($name)) {
                $this->addSql($sql);
            }
        }
    }
}
