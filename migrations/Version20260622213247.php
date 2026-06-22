<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260622213247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bug_report (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(32) NOT NULL, severity VARCHAR(32) NOT NULL, action_description LONGTEXT NOT NULL, observed_result LONGTEXT NOT NULL, expected_result LONGTEXT DEFAULT NULL, route_name VARCHAR(255) DEFAULT NULL, path VARCHAR(2048) NOT NULL, query_string LONGTEXT DEFAULT NULL, locale VARCHAR(10) NOT NULL, theme VARCHAR(10) NOT NULL, user_agent LONGTEXT DEFAULT NULL, viewport_width INT DEFAULT NULL, viewport_height INT DEFAULT NULL, referrer LONGTEXT DEFAULT NULL, correlation_id VARCHAR(128) DEFAULT NULL, app_version VARCHAR(64) DEFAULT NULL, action_timeline_json JSON DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, archive_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reporter_user_id INT NOT NULL, resolved_by_user_id INT DEFAULT NULL, archived_by_user_id INT DEFAULT NULL, INDEX IDX_F6F2DC7ADF3D6D95 (reporter_user_id), INDEX IDX_F6F2DC7AAC78F73B (resolved_by_user_id), INDEX IDX_F6F2DC7AACEC367 (archived_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bug_report ADD CONSTRAINT FK_F6F2DC7ADF3D6D95 FOREIGN KEY (reporter_user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bug_report ADD CONSTRAINT FK_F6F2DC7AAC78F73B FOREIGN KEY (resolved_by_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bug_report ADD CONSTRAINT FK_F6F2DC7AACEC367 FOREIGN KEY (archived_by_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bug_report DROP FOREIGN KEY FK_F6F2DC7ADF3D6D95');
        $this->addSql('ALTER TABLE bug_report DROP FOREIGN KEY FK_F6F2DC7AAC78F73B');
        $this->addSql('ALTER TABLE bug_report DROP FOREIGN KEY FK_F6F2DC7AACEC367');
        $this->addSql('DROP TABLE bug_report');
    }
}
