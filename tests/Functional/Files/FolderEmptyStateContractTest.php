<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for folder empty-state rendering.
 */
final class FolderEmptyStateContractTest extends TestCase
{
    /**
     * @brief Read a repository file and return raw source.
     * @param string $relativePath Repo-relative file path.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Listing fragment must define filtered-empty, folder-empty, then generic-empty priority.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testListingFragmentDefinesEmptyMessagePriority(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString('files.list.filtered_empty', $source);
        self::assertStringContainsString('files.list.folder_empty', $source);
        self::assertStringContainsString('files.list.empty', $source);
        self::assertStringContainsString('isCurrentFolderCompletelyEmpty', $source);
        self::assertStringContainsString('if folders is empty', $source);
    }

    /**
     * @brief Files controller must expose explicit folder empty-state boolean for Twig.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testFilesControllerExposesCurrentFolderEmptyBoolean(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("'isCurrentFolderCompletelyEmpty' => \$folders === [] && \$files === []", $source);
    }
}
