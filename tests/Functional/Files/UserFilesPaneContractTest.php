<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for UserFilesPane markup and resolver canonical scope.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class UserFilesPaneContractTest extends TestCase
{
    /**
     * @brief Read repository file contents as a raw string.
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
     * @brief Resolver exposes canonicalViewScope and subjectUserId for URL normalization.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testResolverExportsCanonicalViewScope(): void
    {
        $resolver = $this->readSource('src/Service/File/FilesQueryScopeResolver.php');

        self::assertStringContainsString("'canonicalViewScope'", $resolver);
        self::assertStringContainsString("'subjectUserId'", $resolver);
        self::assertStringContainsString("view_scope", $resolver);
        self::assertStringContainsString('subject_user', $resolver);
    }

    /**
     * @brief User pane Twig exposes stable pane attributes for JS selection lock.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testUserFilesPaneTemplateHasDataAttributes(): void
    {
        $twig = $this->readSource('templates/files/components/_user_files_pane.html.twig');
        $owned = $this->readSource('templates/files/components/_user_files_pane_owned_table.html.twig');
        $ownedGrid = $this->readSource('templates/files/components/_user_files_pane_owned_grid.html.twig');
        $sharedGrid = $this->readSource('templates/files/components/_user_files_pane_shared_grid.html.twig');

        self::assertStringContainsString('data-files-pane-id="{{ pane.paneId }}"', $twig);
        self::assertStringContainsString('data-files-subject-user-id="{{ pane.subjectUserId }}"', $twig);
        self::assertStringContainsString('accordion-item', $twig);
        self::assertStringContainsString('accordion-button', $twig);
        self::assertStringContainsString("_user_files_pane_owned_grid.html.twig", $twig);
        self::assertStringContainsString("_user_files_pane_shared_grid.html.twig", $twig);
        self::assertStringNotContainsString('files.admin.users_pane.grid_fallback', $twig);
        self::assertStringContainsString('data-files-pane-scope="{{ pane.paneId }}"', $owned);
        self::assertStringContainsString('data-files-select-all-pane="{{ pane.paneId }}"', $owned);
        self::assertStringContainsString("data-files-select-folder-id=\"{{ folder.id }}\"", $owned);
        self::assertStringContainsString('data-files-select-all-pane="{{ pane.paneId }}"', $ownedGrid);
        self::assertStringContainsString('data-files-pane-scope="{{ pane.paneId }}"', $ownedGrid);
        self::assertStringContainsString('data-files-pane-id="{{ pane.paneId }}"', $ownedGrid);
        self::assertStringContainsString('data-files-pane-row="{{ pane.paneId }}"', $ownedGrid);
        self::assertStringContainsString('data-files-select-all-pane="{{ pane.paneId }}"', $sharedGrid);
        self::assertStringContainsString('data-files-pane-scope="{{ pane.paneId }}"', $sharedGrid);
        self::assertStringContainsString('data-files-pane-id="{{ pane.paneId }}"', $sharedGrid);
        self::assertStringContainsString('data-files-pane-row="{{ pane.paneId }}"', $sharedGrid);
        self::assertStringNotContainsString('files-user-pane__scope-menu', $twig);
        self::assertStringNotContainsString('data-files-listing-scope="owned"', $twig);
        self::assertStringNotContainsString('data-files-section-toggle', $twig);
    }

    /**
     * @brief Files-space selection UI accounts for multi-pane focus (activePaneId).
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesSpaceJsLocksSelectionByPane(): void
    {
        $js = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('getActivePaneIdFromSelection', $js);
        self::assertStringContainsString('activePaneId', $js);
        self::assertStringContainsString('data-files-pane-scope', $js);
        self::assertStringContainsString('applySelectAllInScope', $js);
        self::assertStringContainsString('getDistinctPaneIdsFromSelection', $js);
        self::assertStringContainsString('getSubjectUserIdFromActivePane', $js);
    }

    /**
     * @brief Controller filters propagate canonical listing query keys.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilterListingRouteParamsIncludesViewScopeKeys(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("'view_scope'", $controller);
        self::assertStringContainsString("'users_page'", $controller);
        self::assertStringContainsString("'subject_user'", $controller);
        self::assertStringContainsString("(uf|sf)_", $controller);
    }

    /**
     * @brief Listing fragment must not use the removed global "all users" empty accordion fallback.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testListingFragmentDoesNotContainRemovedAdminAllFallbackAccordion(): void
    {
        $listing = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertStringNotContainsString('files.admin.view_scope_all', $listing);
    }

    /**
     * @brief Shared pane table mirrors select-all pane attribute for admin godview.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testSharedUserPaneTableHasSelectAllPaneAttribute(): void
    {
        $shared = $this->readSource('templates/files/components/_user_files_pane_shared_table.html.twig');

        self::assertStringContainsString('data-files-select-all-pane="{{ pane.paneId }}"', $shared);
    }
}
