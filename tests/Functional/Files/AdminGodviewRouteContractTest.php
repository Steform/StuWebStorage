<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for admin godview routing and files templates bindings.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class AdminGodviewRouteContractTest extends TestCase
{
    /**
     * @brief Read repo file contents as a raw string.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief FilesController must expose /admin/files and force admin_context query.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesControllerDeclaresAdminFilesRoute(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("#[Route('/admin/files', name: 'admin_files_index', methods: ['GET'])]", $source);
        self::assertStringContainsString("#[IsGranted('ROLE_ADMIN')]", $source);
        self::assertStringContainsString("\$request->query->set('admin_context', '1');", $source);
    }

    /**
     * @brief Legacy admin controller file must be removed after migration to /admin/files.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testLegacyAdminControllerIsRemoved(): void
    {
        $path = dirname(__DIR__, 3).'/src/Controller/Admin/LegacyAdminFilesControllerGhost.php';

        self::assertFileDoesNotExist($path);
    }

    /**
     * @brief Files index template must expose admin godview controls and banner keys.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesIndexTemplateExposesAdminGodviewControls(): void
    {
        $source = $this->readSource('templates/files/index.html.twig');

        self::assertStringContainsString('files.admin.owner_filter_label', $source);
        self::assertStringContainsString('name="admin_context" value="1"', $source);
        self::assertStringContainsString('name="admin_view_scope"', $source);
        self::assertStringContainsString('name="owner_query"', $source);
        self::assertStringContainsString('data-files-admin-view-scope="owner"', $source);
        self::assertStringContainsString('data-files-admin-view-scope="all"', $source);
        self::assertStringContainsString("path(filesIndexRoute|default('files_index')", $source);
    }

    /**
     * @brief FilesController must expose admin owner suggest endpoint protected by ROLE_ADMIN.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesControllerDeclaresAdminOwnerSuggestRoute(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("#[Route('/admin/files/owners/suggest', name: 'admin_files_owner_suggest', methods: ['GET'])]", $source);
        self::assertStringContainsString("#[IsGranted('ROLE_ADMIN')]", $source);
        self::assertStringContainsString('searchActiveUsersForAdminOwnerSuggest', $source);
        self::assertStringContainsString("#[Route('/admin/files/owners/resolve', name: 'admin_files_owner_resolve', methods: ['GET'])]", $source);
        self::assertStringContainsString('findActiveUsersMatchingExactPseudo', $source);
    }

    /**
     * @brief /admin/files reduces listing min-heights so the admin dashboard strip does not force a page scrollbar.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesIndexScopesAdminFilesRouteHeightModifier(): void
    {
        $twig = $this->readSource('templates/files/index.html.twig');
        $css = $this->readSource('public/css/files-space.css');

        self::assertStringContainsString('files-space-page--admin-files-route', $twig);
        self::assertStringContainsString('admin_files_index', $twig);
        self::assertStringContainsString('.files-space-page.files-space-page--admin-files-route', $css);
    }
}
