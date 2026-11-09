<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contracts: upload failures must be written to the environment log (prod.log).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class UploadDiagnosticLogContractTest extends TestCase
{
    /**
     * @brief Read repository source.
     * @param string $relativePath Relative path.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Prod writes the upload channel at info into the same file as main (prod.log).
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testProdMonologWritesUploadChannelToEnvironmentLogAtInfo(): void
    {
        $yaml = $this->readSource('config/packages/monolog.yaml');
        self::assertStringContainsString('- upload', $yaml);
        self::assertStringContainsString('channels: ["!event", "!deprecation", "!upload"]', $yaml);
        self::assertStringContainsString("path: \"%kernel.logs_dir%/%kernel.environment%.log\"", $yaml);
        self::assertMatchesRegularExpression('/upload:\s*\n\s*type: stream\s*\n\s*path: "%kernel.logs_dir%\/%kernel.environment%.log"\s*\n\s*level: info\s*\n\s*channels: \["upload"\]/', $yaml);
    }

    /**
     * @brief Services and HTTP layer log precise upload failures; chunks are not logged on success.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testUploadPipelineLogsFailuresWithoutPerChunkSuccess(): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/src/Service/Audit/UploadDiagnosticLogger.php');

        $logger = $this->readSource('src/Service/Audit/UploadDiagnosticLogger.php');
        self::assertStringContainsString("monolog.logger.upload", $logger);
        self::assertStringContainsString('permission_denied', $logger);
        self::assertStringContainsString('php_last_error', $logger);
        self::assertStringContainsString('disk_free_bytes', $logger);

        $chunked = $this->readSource('src/Service/File/ChunkedUploadService.php');
        self::assertStringContainsString('UploadDiagnosticLogger', $chunked);
        self::assertStringContainsString("upload.session.created", $chunked);
        self::assertStringContainsString("upload.mkdir", $chunked);
        self::assertStringContainsString('throwLogged', $chunked);
        self::assertStringNotContainsString("upload.chunk.created", $chunked);
        self::assertDoesNotMatchRegularExpression('/function appendChunk[\s\S]*uploadDiagnosticLogger->info\(/', $chunked);

        $job = $this->readSource('src/Service/File/UploadEncryptJobService.php');
        self::assertStringContainsString("upload.encrypt.failed", $job);
        self::assertStringContainsString("upload.encrypt.mkdir", $job);
        self::assertStringContainsString("upload.encrypt.created", $job);
        self::assertStringContainsString("upload.encrypt.done", $job);

        $controller = $this->readSource('src/Controller/FilesController.php');
        self::assertStringContainsString('UploadDiagnosticLogger', $controller);
        self::assertStringContainsString('logUploadHttpFailure', $controller);
        self::assertStringContainsString('upload.finalize.failed', $controller);
        self::assertStringContainsString('upload.chunk.failed', $controller);

        $subscriber = $this->readSource('src/EventSubscriber/JsonApiExceptionSubscriber.php');
        self::assertStringContainsString('upload.uncaught', $subscriber);
        self::assertStringContainsString('isUploadRequest', $subscriber);
    }
}
