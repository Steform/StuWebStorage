<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Add resend tracking columns to login TOTP challenges.
 */
final class Version20260615143000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add last_sent_at and resend_count columns to login_totp_challenge.';
    }

    /**
     * @brief Add resend tracking columns.
     *
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE login_totp_challenge ADD last_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE login_totp_challenge SET last_sent_at = created_at');
        $this->addSql('ALTER TABLE login_totp_challenge MODIFY last_sent_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE login_totp_challenge ADD resend_count INT DEFAULT 0 NOT NULL');
    }

    /**
     * @brief Remove resend tracking columns.
     *
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE login_totp_challenge DROP last_sent_at');
        $this->addSql('ALTER TABLE login_totp_challenge DROP resend_count');
    }
}
