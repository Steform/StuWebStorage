<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\RuntimePermissionFailure;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for runtime permission failure detection.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class RuntimePermissionFailureTest extends TestCase
{
    /**
     * @brief Permission-denied PHP warnings and mkdir_failed keys are detected.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testMatchesPermissionDeniedAndMkdirKeys(): void
    {
        self::assertTrue(RuntimePermissionFailure::matches(new \RuntimeException(RuntimePermissionFailure::EXCEPTION)));
        self::assertTrue(RuntimePermissionFailure::matches(new \ErrorException('mkdir(): Permission denied')));
        self::assertTrue(RuntimePermissionFailure::matches(new \RuntimeException('upload_encrypt.mkdir_failed')));
        self::assertTrue(RuntimePermissionFailure::matches(new \RuntimeException('file_put_contents(...): Failed to open stream: Permission denied')));
        self::assertFalse(RuntimePermissionFailure::matches(new \RuntimeException('chunk_upload.too_large')));
        self::assertFalse(RuntimePermissionFailure::matches(new \RuntimeException('files.flash.disk_full')));
    }
}
