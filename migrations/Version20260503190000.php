<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: persist files UI preferences per user and per device.
 */
final class Version20260503190000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     * @param void No input parameter.
     * @return string
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Create user_device_ui_preference table for files UI persistence by user and device.';
    }

    /**
     * @brief Apply schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_device_ui_preference (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, device_id VARCHAR(128) NOT NULL, files_view_mode VARCHAR(8) NOT NULL DEFAULT \'list\', files_scope VARCHAR(8) NOT NULL DEFAULT \'both\', cloud_visibility_state JSON DEFAULT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_user_device_ui_preference_user (user_id), UNIQUE INDEX uniq_user_device_ui_preference_user_device (user_id, device_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_device_ui_preference ADD CONSTRAINT FK_4A5A2CCEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    /**
     * @brief Revert schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_device_ui_preference DROP FOREIGN KEY FK_4A5A2CCEA76ED395');
        $this->addSql('DROP TABLE user_device_ui_preference');
    }
}
