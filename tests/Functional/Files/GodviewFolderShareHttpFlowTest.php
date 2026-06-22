<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract guard for Godview share HTTP flows on folder endpoints.
 * @date 2026-05-05
 * @author Stephane H.
 */
final class GodviewFolderShareHttpFlowTest extends TestCase
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
     * @brief Folder share/state/properties endpoints must support Godview owner resolution.
     * @return void
     * @date 2026-05-05
     * @author Stephane H.
     */
    public function testGodviewFolderEndpointsUseSubjectResolver(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("#[Route('/files/folders/{id}/share/public'", $source);
        self::assertStringContainsString("#[Route('/files/folders/{id}/share/friends'", $source);
        self::assertStringContainsString("#[Route('/files/folders/{id}/share/state'", $source);
        self::assertStringContainsString("#[Route('/files/folders/{id}/properties'", $source);
        self::assertStringContainsString("#[Route('/files/folders/{id}/public-landing-url'", $source);
        self::assertStringContainsString('public function folderShareState(Request $request, int $id, TranslatorInterface $translator): JsonResponse', $source);
        self::assertStringContainsString('public function folderProperties(Request $request, int $id): JsonResponse', $source);
        self::assertStringContainsString('tryResolveEffectiveOwnerIdForAdminSubject($request', $source);
    }
}
