<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Audit;

use App\Service\Audit\UploadDiagnosticLogger;
use App\Service\Infrastructure\RuntimePermissionFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * @brief Unit tests for permanent upload diagnostics (prod.log payload).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class UploadDiagnosticLoggerTest extends TestCase
{
    /**
     * @brief Failures record exception class, permission flag, php user, and disk snapshot.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testFailEnrichesPermissionDeniedWithFilesystemContext(): void
    {
        $recording = new RecordingLogger();
        $logger = new UploadDiagnosticLogger($recording, sys_get_temp_dir());
        $path = sys_get_temp_dir();
        $exception = new \RuntimeException(RuntimePermissionFailure::EXCEPTION);

        $logger->fail('upload.encrypt.mkdir', [
            'owner_user_id' => 3,
            'task_id' => 'abc',
            'path' => $path,
            'ignored_array' => ['nope'],
        ], $exception);

        self::assertCount(1, $recording->records);
        $record = $recording->records[0];
        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertStringContainsString('upload.failed upload.encrypt.mkdir', $record['message']);
        self::assertSame(3, $record['context']['owner_user_id']);
        self::assertSame('abc', $record['context']['task_id']);
        self::assertSame(RuntimePermissionFailure::EXCEPTION, $record['context']['exception_message']);
        self::assertTrue($record['context']['permission_denied']);
        self::assertArrayHasKey('php_user', $record['context']);
        self::assertArrayHasKey('disk_free_bytes', $record['context']);
        self::assertArrayHasKey('dir_writable', $record['context']);
        self::assertArrayNotHasKey('ignored_array', $record['context']);
    }

    /**
     * @brief Milestones use info so they reach prod.log via the dedicated upload handler.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testInfoAndWarnUseNonErrorLevels(): void
    {
        $recording = new RecordingLogger();
        $logger = new UploadDiagnosticLogger($recording, sys_get_temp_dir());
        $logger->info('upload.session.created', ['upload_id' => 'u1']);
        $logger->warn('upload.session.csrf', ['error_key' => 'files.flash.csrf_invalid']);

        self::assertSame(LogLevel::INFO, $recording->records[0]['level']);
        self::assertSame(LogLevel::WARNING, $recording->records[1]['level']);
        self::assertSame('u1', $recording->records[0]['context']['upload_id']);
    }
}

/**
 * @brief In-memory PSR-3 logger for unit assertions.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @brief Capture one log record.
     * @param mixed $level PSR-3 level.
     * @param string|\Stringable $message Message.
     * @param array<string, mixed> $context Context.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
