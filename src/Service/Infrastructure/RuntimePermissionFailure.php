<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

/**
 * @brief Detect and raise writable-directory failures under var/ (PHP/Apache ownership).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class RuntimePermissionFailure
{
    public const EXCEPTION = 'runtime.permission_denied';

    public const FLASH_KEY = 'files.flash.runtime_permission_denied';

    /**
     * @brief Whether a throwable is a filesystem permission/ownership failure.
     * @param \Throwable $exception Caught error.
     * @return bool
     * @date 2026-11-09
     * @author Stephane H.
     */
    public static function matches(\Throwable $exception): bool
    {
        $message = $exception->getMessage();
        if ($message === self::EXCEPTION) {
            return true;
        }
        if (str_contains($message, 'mkdir_failed')) {
            return true;
        }
        $lower = strtolower($message);

        return str_contains($lower, 'permission denied')
            || str_contains($lower, 'operation not permitted');
    }

    /**
     * @brief Create a directory tree and require it to be writable by the current PHP user.
     * @param string $absoluteDir Absolute directory path.
     * @return void
     * @throws \RuntimeException When mkdir or writability fails.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public static function ensureWritableDirectory(string $absoluteDir): void
    {
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException(self::EXCEPTION);
        }
        if (!is_writable($absoluteDir)) {
            throw new \RuntimeException(self::EXCEPTION);
        }
    }
}
