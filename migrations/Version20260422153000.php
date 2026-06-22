<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422153000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Create Sprint 3 sharing tables (StuWebStorage).';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_file (id INT AUTO_INCREMENT NOT NULL, owner_user_id INT NOT NULL, storage_path VARCHAR(255) NOT NULL, visibility VARCHAR(32) NOT NULL, public_token VARCHAR(128) NOT NULL, UNIQUE INDEX UNIQ_2A08D2A5C69B8EB5 (public_token), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE share_grant (id INT AUTO_INCREMENT NOT NULL, shared_file_id INT NOT NULL, grantee_user_id INT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE public_download_challenge (id INT AUTO_INCREMENT NOT NULL, public_token VARCHAR(128) NOT NULL, email VARCHAR(255) NOT NULL, totp_code VARCHAR(16) NOT NULL, expires_at DATETIME NOT NULL, verified TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public_download_challenge');
        $this->addSql('DROP TABLE share_grant');
        $this->addSql('DROP TABLE shared_file');
    }
}
