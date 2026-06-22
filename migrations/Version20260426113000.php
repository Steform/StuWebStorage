<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426113000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Harden sprint 10 public download challenge and download audit schema.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_download_challenge ADD created_at DATETIME NOT NULL, ADD last_sent_at DATETIME NOT NULL, ADD resend_count INT NOT NULL, ADD attempt_count INT NOT NULL');
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_download_challenge DROP created_at, DROP last_sent_at, DROP resend_count, DROP attempt_count');
    }
}
