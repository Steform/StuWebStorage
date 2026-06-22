<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for shared folder properties in admin godview contexts.
 * @date 2026-05-08
 * @author Stephane H.
 */
final class AdminGodviewSharedFolderPropertiesContextContractTest extends TestCase
{
    /**
     * @brief Read repository source file content.
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
     * @brief Shared-folder properties endpoint must resolve effective grantee from admin godview context.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testSharedFolderPropertiesUsesEffectiveGranteeResolver(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("name: 'files_shared_folder_properties'", $source);
        self::assertStringContainsString('public function sharedFolderProperties(Request $request, int $id): JsonResponse', $source);
        self::assertStringContainsString('tryResolveEffectiveGranteeIdForAdminSubject($request, true)', $source);
        self::assertStringContainsString("['status' => 'error', 'message' => 'files.flash.godview_subject_invalid']", $source);
        self::assertStringContainsString('resolveSharedFolderAccess($id, $effectiveGranteeId)', $source);
    }

    /**
     * @brief Shared-folder access helper must use explicit grantee id input.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testSharedFolderAccessResolverUsesExplicitGrantee(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('private function tryResolveEffectiveGranteeIdForAdminSubject(Request $request, bool $fromQuery = false): ?int', $source);
        self::assertStringContainsString('private function resolveSharedFolderAccess(int $folderId, int $granteeUserId): ?array', $source);
        self::assertStringContainsString('findSharedForGranteeAll($granteeUserId, null)', $source);
    }
}
