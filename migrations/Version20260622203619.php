<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Align legacy MariaDB schema with Doctrine ORM 3 metadata (types, index names).
 *
 * @date 2026-06-22
 * @author Stephane H.
 */
final class Version20260622203619 extends AbstractMigration
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
        return 'Align column types and legacy index names with Doctrine ORM 3 entity metadata.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user CHANGE setup_confirmed setup_confirmed TINYINT NOT NULL, CHANGE active active TINYINT NOT NULL, CHANGE password_reset_required password_reset_required TINYINT NOT NULL, CHANGE session_version session_version INT NOT NULL');
        $this->addSql('DROP INDEX uniq_folder_owner_parent_name ON folder');
        $this->addSql('DROP INDEX idx_folder_owner ON folder');
        $this->addSql('DROP INDEX idx_folder_owner_parent ON folder');
        $this->addSql('ALTER TABLE folder CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE public_share_expires_at public_share_expires_at DATETIME DEFAULT NULL, CHANGE friends_share_user_ids friends_share_user_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE folder RENAME INDEX uniq_folder_public_folder_token TO UNIQ_ECA209CD15C202D3');
        $this->addSql('ALTER TABLE folder RENAME INDEX idx_folder_parent TO IDX_ECA209CDE76796AC');
        $this->addSql('ALTER TABLE login_totp_challenge CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE consumed_at consumed_at DATETIME DEFAULT NULL, CHANGE last_sent_at last_sent_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE password_reset_request RENAME INDEX uniq_7ce748aa5f37a13b TO UNIQ_C5D0A95A5F37A13B');
        $this->addSql('ALTER TABLE profile_email_change_request CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('DROP INDEX idx_share_grant_grantee_user_id ON share_grant');
        $this->addSql('DROP INDEX idx_share_grant_expires_at ON share_grant');
        $this->addSql('DROP INDEX idx_share_grant_shared_file_id ON share_grant');
        $this->addSql('ALTER TABLE share_grant CHANGE expires_at expires_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX idx_shared_file_is_public ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_expires_at ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_public_expires_at ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_updated_at ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_file_extension ON shared_file');
        $this->addSql('ALTER TABLE shared_file CHANGE original_file_name original_file_name VARCHAR(255) NOT NULL, CHANGE byte_size byte_size BIGINT NOT NULL, CHANGE uploaded_at uploaded_at DATETIME NOT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE file_extension file_extension VARCHAR(32) NOT NULL, CHANGE public_expires_at public_expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE shared_file RENAME INDEX uniq_2a08d2a5c69b8eb5 TO UNIQ_36695D88AE981E3B');
        $this->addSql('ALTER TABLE shared_file RENAME INDEX idx_shared_file_folder_id TO IDX_36695D88162CB942');
        $this->addSql('DROP INDEX uniq_trusted_device_user_fingerprint ON trusted_device');
        $this->addSql('DROP INDEX idx_user_deletion_snapshot_created ON user_deletion_snapshot');
        $this->addSql('DROP INDEX idx_user_deletion_snapshot_target ON user_deletion_snapshot');
        $this->addSql('DROP INDEX idx_user_deletion_snapshot_status ON user_deletion_snapshot');
        $this->addSql('ALTER TABLE user_deletion_snapshot CHANGE created_at created_at DATETIME NOT NULL, CHANGE restored_at restored_at DATETIME DEFAULT NULL, CHANGE purged_at purged_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_user_device_ui_preference_user_device ON user_device_ui_preference');
        $this->addSql('ALTER TABLE user_device_ui_preference CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE user_device_ui_preference RENAME INDEX idx_user_device_ui_preference_user TO IDX_7766A91A76ED395');
        $this->addSql('ALTER TABLE user_invitation_token CHANGE created_at created_at DATETIME NOT NULL, CHANGE expires_at expires_at DATETIME NOT NULL, CHANGE consumed_at consumed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user_invitation_token RENAME INDEX uniq_8f40a56e81a90d8e TO UNIQ_4EE977C7B3BC57DA');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user CHANGE setup_confirmed setup_confirmed TINYINT DEFAULT 0 NOT NULL, CHANGE active active TINYINT DEFAULT 1 NOT NULL, CHANGE password_reset_required password_reset_required TINYINT DEFAULT 0 NOT NULL, CHANGE session_version session_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE folder CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE public_share_expires_at public_share_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE friends_share_user_ids friends_share_user_ids JSON DEFAULT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_folder_owner_parent_name ON folder (owner_user_id, parent_folder_id, name_normalized)');
        $this->addSql('CREATE INDEX idx_folder_owner ON folder (owner_user_id)');
        $this->addSql('CREATE INDEX idx_folder_owner_parent ON folder (owner_user_id, parent_folder_id)');
        $this->addSql('ALTER TABLE folder RENAME INDEX idx_eca209cde76796ac TO idx_folder_parent');
        $this->addSql('ALTER TABLE folder RENAME INDEX uniq_eca209cd15c202d3 TO UNIQ_FOLDER_PUBLIC_FOLDER_TOKEN');
        $this->addSql('ALTER TABLE login_totp_challenge CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE consumed_at consumed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_sent_at last_sent_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE password_reset_request RENAME INDEX uniq_c5d0a95a5f37a13b TO UNIQ_7CE748AA5F37A13B');
        $this->addSql('ALTER TABLE profile_email_change_request CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE shared_file CHANGE original_file_name original_file_name VARCHAR(255) DEFAULT \'file\' NOT NULL, CHANGE byte_size byte_size BIGINT DEFAULT 0 NOT NULL, CHANGE uploaded_at uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE file_extension file_extension VARCHAR(32) DEFAULT \'\' NOT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE public_expires_at public_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_shared_file_is_public ON shared_file (is_public)');
        $this->addSql('CREATE INDEX idx_shared_file_expires_at ON shared_file (expires_at)');
        $this->addSql('CREATE INDEX idx_shared_file_public_expires_at ON shared_file (public_expires_at)');
        $this->addSql('CREATE INDEX idx_shared_file_updated_at ON shared_file (updated_at)');
        $this->addSql('CREATE INDEX idx_shared_file_file_extension ON shared_file (file_extension)');
        $this->addSql('ALTER TABLE shared_file RENAME INDEX uniq_36695d88ae981e3b TO UNIQ_2A08D2A5C69B8EB5');
        $this->addSql('ALTER TABLE shared_file RENAME INDEX idx_36695d88162cb942 TO idx_shared_file_folder_id');
        $this->addSql('ALTER TABLE share_grant CHANGE expires_at expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_share_grant_grantee_user_id ON share_grant (grantee_user_id)');
        $this->addSql('CREATE INDEX idx_share_grant_expires_at ON share_grant (expires_at)');
        $this->addSql('CREATE INDEX idx_share_grant_shared_file_id ON share_grant (shared_file_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trusted_device_user_fingerprint ON trusted_device (user_id, device_fingerprint)');
        $this->addSql('ALTER TABLE user_deletion_snapshot CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE restored_at restored_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE purged_at purged_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_user_deletion_snapshot_created ON user_deletion_snapshot (created_at)');
        $this->addSql('CREATE INDEX idx_user_deletion_snapshot_target ON user_deletion_snapshot (target_user_id)');
        $this->addSql('CREATE INDEX idx_user_deletion_snapshot_status ON user_deletion_snapshot (status)');
        $this->addSql('ALTER TABLE user_device_ui_preference CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_device_ui_preference_user_device ON user_device_ui_preference (user_id, device_id)');
        $this->addSql('ALTER TABLE user_device_ui_preference RENAME INDEX idx_7766a91a76ed395 TO idx_user_device_ui_preference_user');
        $this->addSql('ALTER TABLE user_invitation_token CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE expires_at expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE consumed_at consumed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE user_invitation_token RENAME INDEX uniq_4ee977c7b3bc57da TO UNIQ_8F40A56E81A90D8E');
    }
}
