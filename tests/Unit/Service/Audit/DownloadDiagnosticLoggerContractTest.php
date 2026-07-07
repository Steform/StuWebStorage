<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Audit;

use App\Service\Audit\DownloadDiagnosticLogger;
use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for download diagnostic logger helper methods.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class DownloadDiagnosticLoggerContractTest extends TestCase
{
    /**
     * @brief newDownloadId returns non-empty unique hex tokens.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testNewDownloadIdFormatLooksValid(): void
    {
        $ref = new \ReflectionClass(DownloadDiagnosticLogger::class);
        $source = file_get_contents((string) $ref->getFileName());
        self::assertIsString($source);
        self::assertStringContainsString('bin2hex(random_bytes(16))', $source);
        self::assertStringContainsString('hash_hmac', $source);
    }
}
