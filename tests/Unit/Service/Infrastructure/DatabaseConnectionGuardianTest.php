<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\DatabaseConnectionGuardian;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * @brief Unit tests for MySQL gone-away reconnect / single retry.
 * @date 2026-11-10
 * @author Stephane H.
 */
final class DatabaseConnectionGuardianTest extends TestCase
{
    /**
     * @brief ConnectionLost and 2006 messages are detected through the chain.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function testMatchesConnectionLostAndGoneAwayMessages(): void
    {
        self::assertTrue(DatabaseConnectionGuardian::matches(new \RuntimeException(
            DatabaseConnectionGuardian::EXCEPTION
        )));
        self::assertTrue(DatabaseConnectionGuardian::matches(new \RuntimeException(
            'An exception occurred in the driver: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
            0,
            new \Exception('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')
        )));
        self::assertTrue(DatabaseConnectionGuardian::matches(new \RuntimeException(
            'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'
        )));
        self::assertFalse(DatabaseConnectionGuardian::matches(new \RuntimeException('chunk_upload.too_large')));
    }

    /**
     * @brief withRetry closes the connection and succeeds on the second attempt.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function testWithRetryReconnectsOnceThenSucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('close');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getConnection')->willReturn($connection);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);
        $doctrine->method('getConnection')->willReturn($connection);

        $logger = new RecordingLogger();
        $guardian = new DatabaseConnectionGuardian($doctrine, $logger);

        $attempts = 0;
        $result = $guardian->withRetry(static function () use (&$attempts): string {
            ++$attempts;
            if ($attempts === 1) {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }

            return 'ok';
        }, ['task_id' => 'abc']);

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('db.reconnect', $logger->records[0]['message']);
        self::assertSame(LogLevel::INFO, $logger->records[1]['level']);
        self::assertSame('db.retry_ok', $logger->records[1]['message']);
    }

    /**
     * @brief Exhausted retry wraps as runtime.db_connection_lost.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function testWithRetryExhaustedThrowsGuardianException(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('close');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($em);
        $doctrine->method('getConnection')->willReturn($connection);

        $guardian = new DatabaseConnectionGuardian($doctrine, new RecordingLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(DatabaseConnectionGuardian::EXCEPTION);

        $guardian->withRetry(static function (): void {
            throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        });
    }

    /**
     * @brief ensureAlive pings and reconnects when the dummy select fails.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function testEnsureAliveReconnectsOnGoneAwayPing(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getDummySelectSQL')->willReturn('SELECT 1');

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::exactly(2))->method('executeQuery')->willReturnOnConsecutiveCalls(
            self::throwException(new \RuntimeException('MySQL server has gone away')),
            $this->createMock(\Doctrine\DBAL\Result::class),
        );
        $connection->expects(self::once())->method('close');

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getConnection')->willReturn($connection);

        $logger = new RecordingLogger();
        $guardian = new DatabaseConnectionGuardian($doctrine, $logger);
        $guardian->ensureAlive(['route' => 'files_upload_encrypt_tick']);

        self::assertSame('db.reconnect', $logger->records[0]['message']);
        self::assertSame('db.retry_ok', $logger->records[1]['message']);
    }
}

/**
 * @brief In-memory PSR-3 logger for unit assertions.
 * @date 2026-11-10
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
     * @date 2026-11-10
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
