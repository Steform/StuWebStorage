<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract guard for Godview share HTTP flows on file endpoints.
 * @date 2026-05-05
 * @author Stephane H.
 */
final class GodviewShareHttpFlowTest extends TestCase
{
    /**
     * @brief Read source file from repository root.
     * @param string $relativePath Relative path.
     * @return string
     * @date 2026-05-05
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Share file endpoints must resolve owner through Godview subject and expose invalid-subject guard.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testGodviewShareEndpointsUseSubjectResolver(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("#[Route('/files/{id}/share/public'", $source);
        self::assertStringContainsString("#[Route('/files/{id}/share/friends'", $source);
        self::assertStringContainsString("#[Route('/files/share/bulk/public'", $source);
        self::assertStringContainsString("#[Route('/files/share/bulk/friends'", $source);
        self::assertStringContainsString('tryResolveEffectiveOwnerIdForAdminSubject($request)', $source);
        self::assertStringContainsString('files.flash.godview_subject_invalid', $source);
        self::assertStringContainsString("\$isGodviewAllUsers = (string) \$adminContextRaw === '1' && (string) \$adminViewScopeRaw === 'all';", $source);
        self::assertStringContainsString('return $isGodviewAllUsers ? null : $selfId;', $source);
    }
}
