<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Static contract checks for the "Racine" breadcrumb segment.
 *
 * The contract is: the `files.folder.root` link (rendered through `folderRootLabel`)
 * must only appear when navigating inside a subfolder (owned or shared-for-me).
 * At the root level it must not be rendered, neither in the owned section nor in the
 * shared-for-me section. This test pins the Twig and PHP gates that enforce that contract.
 */
final class BreadcrumbRootHiddenAtRootContractTest extends TestCase
{
    /**
     * @brief Read a repository file and return its raw source.
     * @param string $relativePath Repo-relative path to the file.
     * @return string
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function readSource(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Extract the Twig slice between an opening "{% if condition %}" and its matching "{% endif %}".
     * @param string $source Full Twig source.
     * @param string $openingTag Exact opening conditional tag (e.g. "{% if currentFolder is not null %}").
     * @return string Slice content (without the opening/closing tags); empty string if not found or unbalanced.
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function extractFirstIfBlock(string $source, string $openingTag): string
    {
        $startPosition = strpos($source, $openingTag);
        if ($startPosition === false) {
            return '';
        }
        $cursor = $startPosition + \strlen($openingTag);
        $openCount = 1;
        $sliceStart = $cursor;
        $length = \strlen($source);
        while ($cursor < $length && $openCount > 0) {
            $nextIf = strpos($source, '{% if ', $cursor);
            $nextEndif = strpos($source, '{% endif %}', $cursor);
            if ($nextEndif === false) {
                return '';
            }
            if ($nextIf !== false && $nextIf < $nextEndif) {
                ++$openCount;
                $cursor = $nextIf + 6;
                continue;
            }
            --$openCount;
            if ($openCount === 0) {
                return substr($source, $sliceStart, $nextEndif - $sliceStart);
            }
            $cursor = $nextEndif + 11;
        }

        return '';
    }

    /**
     * @brief Owned breadcrumb (containing folderRootLabel) must be wrapped by `{% if currentFolder is not null %}`.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testOwnedBreadcrumbIsGuardedByCurrentFolderNotNull(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');
        self::assertNotSame('', $source, 'Listing fragment template must be readable.');

        $guardedSlice = $this->extractFirstIfBlock($source, '{% if currentFolder is not null %}');
        self::assertNotSame('', $guardedSlice, 'Owned breadcrumb must be wrapped by {% if currentFolder is not null %}.');

        self::assertStringContainsString('<nav aria-label="', $guardedSlice);
        self::assertStringContainsString("'files.folder.breadcrumb.aria'|trans", $guardedSlice);
        self::assertStringContainsString('class="breadcrumb mb-0"', $guardedSlice);
        self::assertStringContainsString('{{ folderRootLabel }}', $guardedSlice);
        self::assertStringContainsString("path('files_index', breadcrumbQueryBase)", $guardedSlice);
    }

    /**
     * @brief Shared-for-me breadcrumb (containing folderRootLabel) must be wrapped by `{% if sharedForMeCurrentFolderId > 0 %}`.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSharedBreadcrumbIsGuardedBySharedForMeCurrentFolderId(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');
        self::assertNotSame('', $source, 'Listing fragment template must be readable.');

        $guardedSlice = $this->extractFirstIfBlock($source, '{% if sharedForMeCurrentFolderId > 0 %}');
        self::assertNotSame('', $guardedSlice, 'Shared breadcrumb must be wrapped by {% if sharedForMeCurrentFolderId > 0 %}.');

        self::assertStringContainsString('<nav aria-label="', $guardedSlice);
        self::assertStringContainsString("'files.folder.breadcrumb.aria'|trans", $guardedSlice);
        self::assertStringContainsString('class="breadcrumb mb-0"', $guardedSlice);
        self::assertStringContainsString('{{ folderRootLabel }}', $guardedSlice);
        self::assertStringContainsString("'shared_folder': null", $guardedSlice);
        self::assertStringContainsString('sharedBreadcrumbFolders', $guardedSlice);
    }

    /**
     * @brief `folderRootLabel` must only be rendered inside the two breadcrumb guards (no leak elsewhere).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderRootLabelIsOnlyRenderedInsideBreadcrumbGuards(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');
        self::assertNotSame('', $source, 'Listing fragment template must be readable.');

        $renderOccurrences = substr_count($source, '{{ folderRootLabel }}');
        self::assertSame(
            2,
            $renderOccurrences,
            'folderRootLabel must be rendered exactly twice (owned breadcrumb + shared breadcrumb).'
        );

        $ownedSlice = $this->extractFirstIfBlock($source, '{% if currentFolder is not null %}');
        $sharedSlice = $this->extractFirstIfBlock($source, '{% if sharedForMeCurrentFolderId > 0 %}');
        $ownedCount = substr_count($ownedSlice, '{{ folderRootLabel }}');
        $sharedCount = substr_count($sharedSlice, '{{ folderRootLabel }}');
        self::assertSame(
            1,
            $ownedCount,
            'folderRootLabel must be rendered once within the owned breadcrumb guard.'
        );
        self::assertSame(
            1,
            $sharedCount,
            'folderRootLabel must be rendered once within the shared breadcrumb guard.'
        );
    }

    /**
     * @brief No `<nav class="breadcrumb">` markup may appear outside the two breadcrumb guards.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testBreadcrumbNavMarkupOnlyExistsInsideGuards(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');
        self::assertNotSame('', $source, 'Listing fragment template must be readable.');

        $totalBreadcrumbOccurrences = substr_count($source, '<ol class="breadcrumb mb-0">');
        self::assertSame(
            2,
            $totalBreadcrumbOccurrences,
            'Breadcrumb <ol> markup must appear exactly twice (owned + shared).'
        );

        $ownedSlice = $this->extractFirstIfBlock($source, '{% if currentFolder is not null %}');
        $sharedSlice = $this->extractFirstIfBlock($source, '{% if sharedForMeCurrentFolderId > 0 %}');
        self::assertStringContainsString('<ol class="breadcrumb mb-0">', $ownedSlice);
        self::assertStringContainsString('<ol class="breadcrumb mb-0">', $sharedSlice);
    }

    /**
     * @brief FilesController must force currentFolderId to 0 when the owned section is hidden by listing scope.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilesControllerForcesOwnedFolderToZeroWhenSectionHidden(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');
        self::assertNotSame('', $source, 'FilesController source must be readable.');

        self::assertStringContainsString('UserFilesPaneBuilderService', $source, 'Owned/shared listing is assembled via the pane builder.');
        self::assertStringContainsString('$showOwnedListingSection', $source, 'Listing scope still gates the owned section.');
    }

    /**
     * @brief FilesController must force sharedForMeCurrentFolderId to 0 when the shared section is hidden by listing scope.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilesControllerForcesSharedFolderToZeroWhenSectionHidden(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');
        self::assertNotSame('', $source, 'FilesController source must be readable.');

        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$paneShowShared\s*\)\s*\{\s*\$sharedForMeCurrentFolderId\s*=\s*0\s*;\s*\}/',
            $source,
            'FilesController must zero out $sharedForMeCurrentFolderId when shared section is hidden.'
        );

        $treeServiceSource = $this->readSource('src/Service/Share/SharedForMeTreeService.php');
        self::assertStringContainsString(
            'if ($currentFolderId > 0 && !isset($registry[$currentFolderId]))',
            $treeServiceSource,
            'SharedForMeTreeService must reset to root when the requested shared folder is unknown.'
        );
    }

    /**
     * @brief FolderTreeService::resolveCurrentFolder must return null for null or non-positive folderId (root case).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testResolveCurrentFolderReturnsNullForNullOrInvalidFolderId(): void
    {
        $source = $this->readSource('src/Service/Share/FolderTreeService.php');
        self::assertNotSame('', $source, 'FolderTreeService source must be readable.');

        self::assertMatchesRegularExpression(
            '/public\s+function\s+resolveCurrentFolder\s*\(\s*int\s+\$ownerUserId\s*,\s*\?int\s+\$folderId\s*\)\s*:\s*\?Folder/',
            $source,
            'resolveCurrentFolder signature must accept nullable folderId and return ?Folder.'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$folderId\s*===\s*null\s*\|\|\s*\$folderId\s*<=\s*0\s*\)\s*\{\s*return\s+null\s*;\s*\}/',
            $source,
            'resolveCurrentFolder must return null when folderId is null or non-positive.'
        );

        self::assertMatchesRegularExpression(
            '/public\s+function\s+buildBreadcrumb\s*\(\s*\?Folder\s+\$currentFolder\s*\)\s*:\s*array/',
            $source,
            'buildBreadcrumb signature must accept a nullable Folder and return an array.'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$currentFolder\s+instanceof\s+Folder\s*\)\s*\{\s*return\s+\[\s*\]\s*;\s*\}/',
            $source,
            'buildBreadcrumb must return an empty array when currentFolder is null (root case).'
        );
    }

    /**
     * @brief The `files.folder.root` translation key must exist in all five required locales.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFolderRootKeyExistsInAllLocales(): void
    {
        $locales = ['fr', 'en', 'de', 'lt', 'no'];
        foreach ($locales as $locale) {
            $source = $this->readSource('translations/messages.'.$locale.'.yaml');
            self::assertNotSame('', $source, sprintf('Locale file "%s" must be readable.', $locale));
            self::assertMatchesRegularExpression(
                '/^\s{4}root:\s*[\'"].+[\'"]\s*$/m',
                $source,
                sprintf('Locale "%s" must declare a non-empty leaf for files.folder.root.', $locale)
            );
        }
    }
}
