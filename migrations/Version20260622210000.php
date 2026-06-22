<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @brief Add optional per-user storage quota on app_user.
 *
 * @date 2026-06-22
 * @author Stephane H.
 */
final class Version20260622210000 extends AbstractMigration
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
        return 'Add nullable storage_quota_bytes column on app_user for per-user storage limits.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('app_user')->hasColumn('storage_quota_bytes')) {
            $this->addSql('ALTER TABLE app_user ADD storage_quota_bytes INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('app_user')->hasColumn('storage_quota_bytes')) {
            $this->addSql('ALTER TABLE app_user DROP storage_quota_bytes');
        }
    }
}
