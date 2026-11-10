<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contracts for EPIC-DB-LONGOPS MySQL gone-away resilience on long ticks.
 * @date 2026-11-10
 * @author Stephane H.
 */
final class DatabaseLongOpsContractTest extends TestCase
{
    /**
     * @brief Read repository source.
     * @param string $relativePath Relative path.
     * @return string
     * @date 2026-11-10
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Guardian, encrypt/extract/archive ticks, i18n and subscriber mapping are wired.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function testLongOpsDatabaseResilienceWiring(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/src/Service/Infrastructure/DatabaseConnectionGuardian.php');
        self::assertFileExists($root.'/docs/epics/EPIC-DB-LONGOPS-mysql-gone-away.md');

        $guardian = $this->readSource('src/Service/Infrastructure/DatabaseConnectionGuardian.php');
        self::assertStringContainsString('function ensureAlive', $guardian);
        self::assertStringContainsString('function withRetry', $guardian);
        self::assertStringContainsString('db.reconnect', $guardian);
        self::assertStringContainsString('runtime.db_connection_lost', $guardian);
        self::assertStringContainsString('files.flash.db_connection_lost', $guardian);

        $encrypt = $this->readSource('src/Service/File/UploadEncryptJobService.php');
        self::assertStringContainsString('DatabaseConnectionGuardian', $encrypt);
        self::assertStringContainsString('withRetry', $encrypt);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('ensureAlive', $controller);
        self::assertStringContainsString('DatabaseConnectionGuardian::FLASH_KEY', $controller);
        self::assertStringContainsString('DatabaseConnectionGuardian::EXCEPTION', $controller);

        $extract = $this->readSource('src/Service/File/ZipExtractService.php');
        self::assertStringContainsString('flushDatabase', $extract);
        self::assertStringContainsString('DatabaseConnectionGuardian', $extract);

        $archive = $this->readSource('src/Service/Download/DownloadArchiveService.php');
        self::assertStringContainsString('databaseConnectionGuardian', $archive);

        $archiveController = $this->readSource('src/Controller/DownloadArchiveController.php');
        self::assertStringContainsString('ensureAlive', $archiveController);

        $subscriber = $this->readSource('src/EventSubscriber/JsonApiExceptionSubscriber.php');
        self::assertStringContainsString('DatabaseConnectionGuardian::matches', $subscriber);
        self::assertStringContainsString('DatabaseConnectionGuardian::FLASH_KEY', $subscriber);

        $fr = $this->readSource('translations/messages.fr.yaml');
        self::assertStringContainsString('db_connection_lost:', $fr);
        $en = $this->readSource('translations/messages.en.yaml');
        self::assertStringContainsString('db_connection_lost:', $en);
    }
}
