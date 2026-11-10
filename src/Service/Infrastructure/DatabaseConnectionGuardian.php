<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @brief Detect MySQL gone-away / ConnectionLost, reconnect once, and retry DB work for long ops.
 * @date 2026-11-10
 * @author Stephane H.
 */
final class DatabaseConnectionGuardian
{
    public const EXCEPTION = 'runtime.db_connection_lost';

    public const FLASH_KEY = 'files.flash.db_connection_lost';

    /**
     * @brief Construct the connection guardian.
     * @param ManagerRegistry $doctrine Doctrine registry (connection close / manager check).
     * @param LoggerInterface $logger Upload/db diagnostic channel.
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        #[Autowire(service: 'monolog.logger.upload')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @brief Whether a throwable is a lost-database-connection failure.
     * @param \Throwable $exception Caught error.
     * @return bool
     * @date 2026-11-10
     * @author Stephane H.
     */
    public static function matches(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionLost) {
            return true;
        }
        if ($exception->getMessage() === self::EXCEPTION) {
            return true;
        }
        $cursor = $exception;
        while ($cursor instanceof \Throwable) {
            if ($cursor instanceof ConnectionLost) {
                return true;
            }
            $lower = strtolower($cursor->getMessage());
            if (
                str_contains($lower, 'server has gone away')
                || str_contains($lower, 'lost connection')
                || str_contains($lower, 'error: 2006')
                || str_contains($lower, 'error: 2013')
                || str_contains($lower, 'sqlstate[hy000]: general error: 2006')
                || str_contains($lower, 'sqlstate[hy000]: general error: 2013')
            ) {
                return true;
            }
            $cursor = $cursor->getPrevious();
        }

        return false;
    }

    /**
     * @brief Ping the DB and reconnect when the connection is already dead (tick entry).
     * @param array<string, mixed> $context Scalar log context.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function ensureAlive(array $context = []): void
    {
        $connection = $this->doctrine->getConnection();
        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (\Throwable $exception) {
            if (!self::matches($exception)) {
                throw $exception;
            }
            $this->reconnect($context, $exception);
            $connection = $this->doctrine->getConnection();
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
            $this->logger->info('db.retry_ok', $this->scalarContext($context) + [
                'phase' => 'ensure_alive',
            ]);
        }
    }

    /**
     * @brief Run a DB operation once, reconnect and retry once on ConnectionLost.
     * @param callable(): mixed $operation DB work.
     * @param array<string, mixed> $context Scalar log context.
     * @return mixed
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function withRetry(callable $operation, array $context = []): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $first) {
            if (!self::matches($first)) {
                throw $first;
            }
            $em = $this->doctrine->getManager();
            if (method_exists($em, 'isOpen') && !$em->isOpen()) {
                $this->logger->error('db.retry_failed', $this->scalarContext($context) + [
                    'reason' => 'entity_manager_closed',
                    'exception_class' => $first::class,
                    'exception_message' => $first->getMessage(),
                ]);
                throw new \RuntimeException(self::EXCEPTION, 0, $first);
            }
            $this->reconnect($context, $first);
            try {
                $result = $operation();
                $this->logger->info('db.retry_ok', $this->scalarContext($context) + [
                    'phase' => 'with_retry',
                ]);

                return $result;
            } catch (\Throwable $second) {
                $this->logger->error('db.retry_failed', $this->scalarContext($context) + [
                    'exception_class' => $second::class,
                    'exception_message' => $second->getMessage(),
                    'previous_class' => $first::class,
                    'previous_message' => $first->getMessage(),
                ]);
                throw new \RuntimeException(self::EXCEPTION, 0, $second);
            }
        }
    }

    /**
     * @brief Close the DBAL connection so the next query opens a fresh socket.
     * @param array<string, mixed> $context Scalar log context.
     * @param \Throwable $exception Cause that triggered reconnect.
     * @return void
     * @date 2026-11-10
     * @author Stephane H.
     */
    private function reconnect(array $context, \Throwable $exception): void
    {
        $this->logger->warning('db.reconnect', $this->scalarContext($context) + [
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
        try {
            $this->doctrine->getConnection()->close();
        } catch (\Throwable) {
            // Ignore close errors; the next query will attempt a new connection.
        }
    }

    /**
     * @brief Keep only scalar context values for logs.
     * @param array<string, mixed> $context Caller context.
     * @return array<string, mixed>
     * @date 2026-11-10
     * @author Stephane H.
     */
    private function scalarContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
