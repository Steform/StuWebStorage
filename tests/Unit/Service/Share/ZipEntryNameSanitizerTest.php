<?php

namespace App\Tests\Unit\Service\Share;

use App\Service\Share\ZipEntryNameSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for ZIP entry path sanitization (Zip Slip mitigation).
 * @date 2026-05-02
 * @author Stephane H.
 */
final class ZipEntryNameSanitizerTest extends TestCase
{
    /**
     * @brief Ensure traversal segments are stripped and result has no dot-dot parts.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSanitizeEntryPathRemovesTraversal(): void
    {
        $out = ZipEntryNameSanitizer::sanitizeEntryPath('../../../etc/passwd', 42);
        self::assertStringNotContainsString('..', $out);
        self::assertFalse(str_starts_with($out, '/'), 'ZIP entry path must remain relative');
    }

    /**
     * @brief Ensure backslashes become safe forward-slash segments without escaping upward.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSanitizeEntryPathNormalizesBackslashes(): void
    {
        $out = ZipEntryNameSanitizer::sanitizeEntryPath('folder\\..\\..\\file.txt', 1);
        self::assertStringNotContainsString('..', $out);
        self::assertStringContainsString('file.txt', $out);
    }

    /**
     * @brief Empty path uses fallback file id.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSanitizeEntryPathFallbackWhenOnlyTraversal(): void
    {
        $out = ZipEntryNameSanitizer::sanitizeEntryPath('../..', 99);
        self::assertSame('file_99.bin', $out);
    }
}
