<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: unique nullable public folder token for anonymous folder landing + ZIP.
 */
final class Version20260502140000 extends AbstractMigration
{
    /**
     * @brief Migration description for CLI output.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add folder.public_folder_token for anonymous public folder landing (ZIP scope).';
    }

    /**
     * @brief Apply schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE folder ADD public_folder_token VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FOLDER_PUBLIC_FOLDER_TOKEN ON folder (public_folder_token)');
    }

    /**
     * @brief Revert schema changes.
     * @param Schema $schema Target schema.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_FOLDER_PUBLIC_FOLDER_TOKEN ON folder');
        $this->addSql('ALTER TABLE folder DROP public_folder_token');
    }
}
