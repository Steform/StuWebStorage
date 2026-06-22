<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for trigger subject fallback in godview friends share flow.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class GodviewFriendsShareTriggerSubjectFallbackContractTest extends TestCase
{
    /**
     * @brief Read repository source file.
     * @param string $relativePath Repository-relative path.
     * @return string
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Trigger subject resolver must fallback to nearest subject host attribute.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testGetSubjectUserIdFromTriggerIncludesClosestHostFallback(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function getSubjectUserIdFromTrigger(triggerEl)', $source);
        self::assertStringContainsString("triggerEl.closest('[data-files-subject-user-id]')", $source);
        self::assertStringContainsString("getAttribute('data-files-subject-user-id')", $source);
    }
}
