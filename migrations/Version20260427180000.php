<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427180000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Sprint 16: shared_file updated_at, file_extension, indexes for owner listing.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shared_file ADD updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE shared_file SET updated_at = uploaded_at');
        $this->addSql("ALTER TABLE shared_file MODIFY updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("ALTER TABLE shared_file ADD file_extension VARCHAR(32) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE shared_file SET file_extension = CASE WHEN original_file_name LIKE '%.%' THEN LOWER(SUBSTRING_INDEX(original_file_name, '.', -1)) ELSE '' END");
        $this->addSql('CREATE INDEX idx_shared_file_updated_at ON shared_file (updated_at)');
        $this->addSql('CREATE INDEX idx_shared_file_file_extension ON shared_file (file_extension)');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_shared_file_file_extension ON shared_file');
        $this->addSql('DROP INDEX idx_shared_file_updated_at ON shared_file');
        $this->addSql('ALTER TABLE shared_file DROP updated_at, DROP file_extension');
    }
}
