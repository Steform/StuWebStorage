<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624120000 extends AbstractMigration
{
    /**
     * @brief Describe migration purpose.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getDescription(): string
    {
        return 'Add invitation locale column on user_invitation_token.';
    }

    /**
     * @brief Apply migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_invitation_token ADD locale VARCHAR(5) NOT NULL DEFAULT 'en'");
    }

    /**
     * @brief Revert migration changes.
     * @param Schema $schema Doctrine schema.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_invitation_token DROP locale');
    }
}
