<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for hierarchical shared-for-me folder navigation.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class SharedForMeTreeNavigationContractTest extends TestCase
{
    /**
     * @brief Read a repository file as raw source.
     * @param string $relativePath Path relative to repository root.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Shared tree service must filter folders by parent at the current cursor.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testSharedForMeTreeServiceFiltersFoldersByParentAtCurrentLevel(): void
    {
        $source = $this->readSource('src/Service/Share/SharedForMeTreeService.php');

        self::assertStringContainsString('listFoldersAtLevel', $source);
        self::assertStringContainsString('$folderNode->parentId', $source);
        self::assertStringContainsString('buildBreadcrumb', $source);
        self::assertStringContainsString('computeRecursiveFolderSizes', $source);
    }

    /**
     * @brief Pane builder must delegate shared navigation to SharedForMeTreeService.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testPaneBuilderDelegatesSharedNavigationToTreeService(): void
    {
        $source = $this->readSource('src/Service/File/UserFilesPaneBuilderService.php');

        self::assertStringContainsString('SharedForMeTreeService', $source);
        self::assertStringContainsString('sharedBreadcrumbFolders', $source);
        self::assertStringContainsString('buildListingContext', $source);
        self::assertStringNotContainsString('buildActiveSharedForMeFolders', $source);
    }

    /**
     * @brief Shared templates must render folders at every navigation level and full breadcrumbs.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function testSharedTemplatesExposeFoldersAtEveryLevelAndFullBreadcrumb(): void
    {
        $table = $this->readSource('templates/files/components/_user_files_pane_shared_table.html.twig');
        $grid = $this->readSource('templates/files/components/_user_files_pane_shared_grid.html.twig');
        $fragment = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertStringNotContainsString('{% if sharedForMeCurrentFolderId == 0 %}', $table);
        self::assertStringNotContainsString('sharedForMeCurrentFolderId == 0 and sharedForMeFolders', $grid);
        self::assertStringContainsString('sharedBreadcrumbFolders', $table);
        self::assertStringContainsString('sharedBreadcrumbFolders', $grid);
        self::assertStringContainsString('sharedBreadcrumbFolders', $fragment);
        self::assertStringNotContainsString('{% elseif sharedForMeFolders is not empty and layoutView == \'grid\' %}', $fragment);
    }
}
