<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Sprint contract for the toolbar column visibility toggle: the list
 *        view exposes a Columns dropdown (gated behind `layoutView == 'list'`)
 *        with six checkboxes wired to list columns, where uploaded/modified
 *        columns are rendered hidden by default with d-none on both <th> and
 *        <td>, the JS state machine and translation keys are in place.
 *        Static template inspection avoids booting the kernel.
 * @author Stephane H.
 * @date 2026-04-27
 */
final class ColumnVisibilityToggleTest extends TestCase
{
    /**
     * @brief Read a repo-relative file and return its raw content.
     * @param string $relativePath Repository-relative path to the file.
     * @return string Raw content, or empty string if unreadable.
     * @author Stephane H.
     * @date 2026-04-27
     */
    private function readFile(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root . DIRECTORY_SEPARATOR . $relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief The Columns dropdown must be rendered only when layoutView is list.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testIndexTemplateGatesColumnsDropdownToListView(): void
    {
        $source = $this->readFile('templates/files/index.html.twig');

        self::assertNotSame('', $source, 'index.html.twig must be readable');
        self::assertStringContainsString('id="files-columns-toggle"', $source);
        self::assertStringContainsString('data-files-columns-dropdown', $source);

        self::assertMatchesRegularExpression(
            '/\{%\s*if\s+layoutView\s*==\s*\'list\'\s*%\}[\s\S]*?id="files-columns-toggle"[\s\S]*?\{%\s*endif\s*%\}/',
            $source,
            'Columns dropdown must be wrapped in {% if layoutView == \'list\' %} ... {% endif %}'
        );
    }

    /**
     * @brief The Columns dropdown must expose all six column toggle checkboxes.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testIndexTemplateExposesColumnToggleCheckboxes(): void
    {
        $source = $this->readFile('templates/files/index.html.twig');

        self::assertStringContainsString('data-files-col-toggle="type"', $source);
        self::assertStringContainsString('data-files-col-toggle="size"', $source);
        self::assertStringContainsString('data-files-col-toggle="share_public"', $source);
        self::assertStringContainsString('data-files-col-toggle="share_friends"', $source);
        self::assertStringContainsString('data-files-col-toggle="uploaded"', $source);
        self::assertStringContainsString('data-files-col-toggle="modified"', $source);
        self::assertStringContainsString('id="files-col-toggle-type"', $source);
        self::assertStringContainsString('id="files-col-toggle-size"', $source);
        self::assertStringContainsString('id="files-col-toggle-share-public"', $source);
        self::assertStringContainsString('id="files-col-toggle-share-friends"', $source);
        self::assertStringContainsString('id="files-col-toggle-uploaded"', $source);
        self::assertStringContainsString('id="files-col-toggle-modified"', $source);
        self::assertStringContainsString('data-bs-auto-close="outside"', $source);
        self::assertStringContainsString("'files.toolbar.columns_label'|trans", $source);
        self::assertStringContainsString("'files.toolbar.columns_aria'|trans", $source);
    }

    /**
     * @brief Listing fragment must mark uploaded/modified <th> as d-none by default.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testListingFragmentHidesUploadedAndModifiedHeaders(): void
    {
        $source = $this->readFile('templates/files/_listing_fragment.html.twig');

        self::assertNotSame('', $source, 'listing fragment must be readable');
        self::assertMatchesRegularExpression(
            '/<th[^>]*\bclass="[^"]*\bd-none\b[^"]*"[^>]*\bdata-files-col="uploaded"/',
            $source,
            'Uploaded <th> must carry d-none and data-files-col="uploaded"'
        );
        self::assertMatchesRegularExpression(
            '/<th[^>]*\bclass="[^"]*\bd-none\b[^"]*"[^>]*\bdata-files-col="modified"/',
            $source,
            'Modified <th> must carry d-none and data-files-col="modified"'
        );
    }

    /**
     * @brief Listing fragment must expose type/size/public/friends headers as toggleable columns.
     * @return void
     * @author Stephane H.
     * @date 2026-04-29
     */
    public function testListingFragmentExposesVisibleToggleableHeaders(): void
    {
        $source = $this->readFile('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString('data-files-col="type"', $source);
        self::assertStringContainsString('data-files-col="size"', $source);
        self::assertStringContainsString('data-files-col="share_public"', $source);
        self::assertStringContainsString('data-files-col="share_friends"', $source);
    }

    /**
     * @brief Listing fragment must mark uploaded/modified row <td> as d-none by default.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testListingFragmentHidesUploadedAndModifiedCells(): void
    {
        $source = $this->readFile('templates/files/_listing_fragment.html.twig');

        self::assertMatchesRegularExpression(
            '/<td[^>]*\bclass="[^"]*\bd-none\b[^"]*"[^>]*\bdata-files-col="uploaded"/',
            $source,
            'Uploaded <td> must carry d-none and data-files-col="uploaded"'
        );
        self::assertMatchesRegularExpression(
            '/<td[^>]*\bclass="[^"]*\bd-none\b[^"]*"[^>]*\bdata-files-col="modified"/',
            $source,
            'Modified <td> must carry d-none and data-files-col="modified"'
        );
    }

    /**
     * @brief The JS state machine must declare the toggleable columns and the persistence helpers.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testFilesSpaceJsImplementsColumnVisibilityStateMachine(): void
    {
        $source = $this->readFile('public/js/files-space.js');

        self::assertNotSame('', $source, 'files-space.js must be readable');
        self::assertStringContainsString("COLUMN_PREF_KEY = 'files.columns.visibility'", $source);
        self::assertStringContainsString("['type', 'size', 'share_public', 'share_friends', 'uploaded', 'modified']", $source);
        self::assertStringContainsString('COLUMN_VISIBILITY_DEFAULTS', $source);
        self::assertStringContainsString('function ensureColumnPrefsCanonical', $source);
        self::assertStringContainsString('function readColumnPrefs', $source);
        self::assertStringContainsString('function writeColumnPrefs', $source);
        self::assertStringContainsString('function applyColumnVisibility', $source);
        self::assertStringContainsString('function syncColumnToggleCheckboxes', $source);
        self::assertStringContainsString('[data-files-col-toggle]', $source);
        self::assertStringContainsString('applyColumnVisibility(liveRegion)', $source);
        self::assertStringContainsString('ensureColumnPrefsCanonical();', $source);
    }

    /**
     * @brief The display dropdown parent must close when scope or layout actions are clicked.
     * @return void
     * @author Stephane H.
     * @date 2026-05-03
     */
    public function testFilesSpaceJsClosesDisplayDropdownForScopeAndLayoutActions(): void
    {
        $source = $this->readFile('public/js/files-space.js');

        self::assertStringContainsString("document.getElementById('files-display-menu-toggle')", $source);
        self::assertStringContainsString("closest('a[data-files-view-toggle]')", $source);
        self::assertStringContainsString("closest('a[data-files-listing-scope]')", $source);
        self::assertStringContainsString('window.bootstrap.Dropdown.getOrCreateInstance(displayMenuToggle).hide();', $source);
    }

    /**
     * @brief All five locales must declare the new toolbar.columns_label and toolbar.columns_aria keys.
     * @return void
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function testRequiredColumnsKeysExistInAllLocales(): void
    {
        $locales = ['fr', 'en', 'de', 'lt', 'no'];
        $requiredLeafs = ['columns_label:', 'columns_aria:', 'none:', 'desc:', 'asc:'];

        foreach ($locales as $locale) {
            $source = $this->readFile('translations/messages.' . $locale . '.yaml');
            self::assertNotSame('', $source, 'Locale file must be readable: ' . $locale);
            foreach ($requiredLeafs as $needle) {
                self::assertStringContainsString(
                    $needle,
                    $source,
                    sprintf('Locale "%s" is missing translation leaf for "%s"', $locale, $needle)
                );
            }
        }
    }
}
