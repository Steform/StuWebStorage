<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Service\Infrastructure\RuntimePermissionFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @brief Permanent upload/encrypt diagnostics written to the environment log (prod.log in production).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class UploadDiagnosticLogger
{
    /**
     * @brief Construct the upload diagnostic logger.
     * @param LoggerInterface $logger Dedicated upload Monolog channel.
     * @param string $projectDir Project root (disk/path probes).
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function __construct(
        #[Autowire(service: 'monolog.logger.upload')]
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @brief Log a successful or informational upload milestone (session created, encrypt done).
     * @param string $event Stable event name (upload.session.created, …).
     * @param array<string, mixed> $context Scalar context (ids, sizes, paths).
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function info(string $event, array $context = []): void
    {
        $this->logger->info('upload '.$event, $this->enrich($context, null));
    }

    /**
     * @brief Log a rejected upload request (CSRF, validation) without treating it as a server fault.
     * @param string $event Stable event name.
     * @param array<string, mixed> $context Scalar context.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function warn(string $event, array $context = []): void
    {
        $this->logger->warning('upload '.$event, $this->enrich($context, null));
    }

    /**
     * @brief Log an upload failure with exception, PHP errno, path probe, and disk snapshot.
     * @param string $event Stable event name (upload.chunk.failed, upload.encrypt.failed, …).
     * @param array<string, mixed> $context Scalar context.
     * @param \Throwable|null $exception Cause when available.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function fail(string $event, array $context = [], ?\Throwable $exception = null): void
    {
        $payload = $this->enrich($context, $exception);
        $reason = (string) ($payload['reason'] ?? $event);
        $this->logger->error('upload.failed '.$event.': '.$reason, $payload);
    }

    /**
     * @brief Merge caller context with filesystem/PHP diagnostics (no file contents).
     * @param array<string, mixed> $context Caller context.
     * @param \Throwable|null $exception Optional cause.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function enrich(array $context, ?\Throwable $exception): array
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

        $out['php_user'] = $this->currentPhpUser();
        $out['php_uid'] = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $lastError = error_get_last();
        if (is_array($lastError) && isset($lastError['message'])) {
            $out['php_last_error'] = (string) $lastError['message'];
            $out['php_last_error_file'] = (string) ($lastError['file'] ?? '');
            $out['php_last_error_line'] = (int) ($lastError['line'] ?? 0);
        }

        $probeDir = $this->projectDir.'/var';
        if (!is_dir($probeDir)) {
            $probeDir = is_dir($this->projectDir) ? $this->projectDir : sys_get_temp_dir();
        }
        $free = @disk_free_space($probeDir);
        if ($free !== false) {
            $out['disk_free_bytes'] = (int) $free;
            $out['disk_probe_path'] = $probeDir;
        }

        $path = isset($out['path']) ? (string) $out['path'] : '';
        if ($path !== '') {
            $out = array_merge($out, $this->pathProbe($path));
        }

        if ($exception instanceof \Throwable) {
            $out['exception_class'] = $exception::class;
            $out['exception_message'] = $exception->getMessage();
            $out['exception_file'] = $exception->getFile().':'.$exception->getLine();
            $out['permission_denied'] = RuntimePermissionFailure::matches($exception);
            $out['reason'] = $exception->getMessage();
            $previous = $exception->getPrevious();
            if ($previous instanceof \Throwable) {
                $out['previous_class'] = $previous::class;
                $out['previous_message'] = $previous->getMessage();
            }
        } elseif (!isset($out['reason'])) {
            $out['reason'] = (string) ($out['error_code'] ?? $out['error_key'] ?? '');
        }

        return $out;
    }

    /**
     * @brief Probe existence, writability, owner, and mode for a file or its parent directory.
     * @param string $path Absolute path.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function pathProbe(string $path): array
    {
        $dir = is_dir($path) ? $path : dirname($path);
        $owner = null;
        $mode = null;
        if (is_dir($dir) || is_file($dir)) {
            if (function_exists('posix_getpwuid')) {
                $pw = @posix_getpwuid((int) @fileowner($dir));
                $owner = is_array($pw) ? (string) ($pw['name'] ?? '') : null;
            }
            $perms = @fileperms($dir);
            $mode = is_int($perms) ? substr(sprintf('%o', $perms), -4) : null;
        }

        return [
            'path_exists' => file_exists($path),
            'path_writable' => is_writable($path),
            'dir' => $dir,
            'dir_exists' => is_dir($dir),
            'dir_writable' => is_dir($dir) && is_writable($dir),
            'dir_owner' => $owner,
            'dir_mode' => $mode,
        ];
    }

    /**
     * @brief Process user name running PHP (www-data vs deploy owner).
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function currentPhpUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return (string) $pw['name'];
            }
        }
        $name = get_current_user();

        return is_string($name) && $name !== '' ? $name : 'unknown';
    }
}
