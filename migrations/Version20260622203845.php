<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Restore storage indexes and unique constraints declared on entity attributes.
 *
 * @date 2026-06-22
 * @author Stephane H.
 */
final class Version20260622203845 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     *
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Restore storage indexes and unique constraints on ORM entity attributes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_folder_owner_parent ON folder (owner_user_id, parent_folder_id)');
        $this->addSql('CREATE INDEX idx_folder_owner ON folder (owner_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_folder_owner_parent_name ON folder (owner_user_id, parent_folder_id, name_normalized)');
        $this->addSql('ALTER TABLE folder RENAME INDEX idx_eca209cde76796ac TO idx_folder_parent');
        $this->addSql('CREATE INDEX idx_share_grant_expires_at ON share_grant (expires_at)');
        $this->addSql('CREATE INDEX idx_share_grant_shared_file_id ON share_grant (shared_file_id)');
        $this->addSql('CREATE INDEX idx_share_grant_grantee_user_id ON share_grant (grantee_user_id)');
        $this->addSql('CREATE INDEX idx_shared_file_expires_at ON shared_file (expires_at)');
        $this->addSql('CREATE INDEX idx_shared_file_updated_at ON shared_file (updated_at)');
        $this->addSql('CREATE INDEX idx_shared_file_file_extension ON shared_file (file_extension)');
        $this->addSql('CREATE INDEX idx_shared_file_is_public ON shared_file (is_public)');
        $this->addSql('CREATE INDEX idx_shared_file_public_expires_at ON shared_file (public_expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trusted_device_user_fingerprint ON trusted_device (user_id, device_fingerprint)');
        $this->addSql('CREATE INDEX idx_user_deletion_snapshot_target ON user_deletion_snapshot (target_user_id)');
        $this->addSql('CREATE INDEX idx_user_deletion_snapshot_status ON user_deletion_snapshot (status)');
        $this->addSql('CREATE INDEX idx_user_deletion_snapshot_created ON user_deletion_snapshot (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_device_ui_preference_user_device ON user_device_ui_preference (user_id, device_id)');
        $this->addSql('ALTER TABLE user_device_ui_preference RENAME INDEX idx_7766a91a76ed395 TO idx_user_device_ui_preference_user');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_folder_owner_parent ON folder');
        $this->addSql('DROP INDEX idx_folder_owner ON folder');
        $this->addSql('DROP INDEX uniq_folder_owner_parent_name ON folder');
        $this->addSql('ALTER TABLE folder RENAME INDEX idx_folder_parent TO IDX_ECA209CDE76796AC');
        $this->addSql('DROP INDEX idx_shared_file_expires_at ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_updated_at ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_file_extension ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_is_public ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_public_expires_at ON shared_file');
        $this->addSql('DROP INDEX idx_share_grant_expires_at ON share_grant');
        $this->addSql('DROP INDEX idx_share_grant_shared_file_id ON share_grant');
        $this->addSql('DROP INDEX idx_share_grant_grantee_user_id ON share_grant');
        $this->addSql('DROP INDEX uniq_trusted_device_user_fingerprint ON trusted_device');
        $this->addSql('DROP INDEX idx_user_deletion_snapshot_target ON user_deletion_snapshot');
        $this->addSql('DROP INDEX idx_user_deletion_snapshot_status ON user_deletion_snapshot');
        $this->addSql('DROP INDEX idx_user_deletion_snapshot_created ON user_deletion_snapshot');
        $this->addSql('DROP INDEX uniq_user_device_ui_preference_user_device ON user_device_ui_preference');
        $this->addSql('ALTER TABLE user_device_ui_preference RENAME INDEX idx_user_device_ui_preference_user TO IDX_7766A91A76ED395');
    }
}
