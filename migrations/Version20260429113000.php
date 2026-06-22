<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429113000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Sprint 24: add folder tree model and link shared_file.folder_id.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE folder (id INT AUTO_INCREMENT NOT NULL, parent_folder_id INT DEFAULT NULL, owner_user_id INT NOT NULL, name VARCHAR(190) NOT NULL, name_normalized VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_folder_owner_parent (owner_user_id, parent_folder_id), INDEX idx_folder_parent (parent_folder_id), INDEX idx_folder_owner (owner_user_id), UNIQUE INDEX uniq_folder_owner_parent_name (owner_user_id, parent_folder_id, name_normalized), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE folder ADD CONSTRAINT FK_FOLDER_PARENT FOREIGN KEY (parent_folder_id) REFERENCES folder (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE shared_file ADD folder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE shared_file ADD CONSTRAINT FK_SHARED_FILE_FOLDER FOREIGN KEY (folder_id) REFERENCES folder (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_shared_file_folder_id ON shared_file (folder_id)');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_file DROP FOREIGN KEY FK_SHARED_FILE_FOLDER');
        $this->addSql('DROP INDEX idx_shared_file_folder_id ON shared_file');
        $this->addSql('ALTER TABLE shared_file DROP folder_id');
        $this->addSql('ALTER TABLE folder DROP FOREIGN KEY FK_FOLDER_PARENT');
        $this->addSql('DROP TABLE folder');
    }
}
