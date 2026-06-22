<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Contract checks for listing_scope query and listing sections (Sprint listing scope toolbar).
 */
final class ListingScopePartialContractTest extends TestCase
{
    /**
     * @brief Read repository file as raw source.
     * @param string $relativePath Repo-relative path.
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
     * @brief Criteria class must expose listing_scope in serialized query params when not default.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testSharedFileOwnerListCriteriaListsListingScopeProperty(): void
    {
        $source = $this->readSource('src/File/SharedFileOwnerListCriteria.php');

        self::assertStringContainsString('listingScope', $source);
        self::assertStringContainsString("'listing_scope'", $source);
    }

    /**
     * @brief Files index toolbar exposes tri-state scope controls with stable data attributes and responsive row hooks.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilesIndexToolbarContainsListingScopeControls(): void
    {
        $source = $this->readSource('templates/files/index.html.twig');

        self::assertStringContainsString('data-files-listing-scope="owned"', $source);
        self::assertStringContainsString('data-files-listing-scope="shared"', $source);
        self::assertStringContainsString('data-files-listing-scope="both"', $source);
        self::assertStringContainsString('files.toolbar.listing_scope_group_aria', $source);
        self::assertStringContainsString('files-toolbar-slot--columns', $source);
        self::assertStringContainsString('files-toolbar-slot--view', $source);
        self::assertStringContainsString('files-toolbar-slot--display', $source);
        self::assertStringContainsString('files-toolbar-md-contents', $source);
        self::assertStringContainsString('files-toolbar-cluster-view-home', $source);
        self::assertStringContainsString('data-files-admin-owner-filter-block', $source);
    }

    /**
     * @brief Listing fragment gates accordion sections with showOwned/showShared flags.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testListingFragmentUsesSectionVisibilityFlags(): void
    {
        $source = $this->readSource('templates/files/_listing_fragment.html.twig');

        self::assertTrue(
            str_contains($source, '{% if showOwnedListingSection %}')
            || str_contains($source, 'elseif showOwnedListingSection')
        );
        self::assertStringContainsString('showSharedListingSection', $source);
    }

    /**
     * @brief Godview admin merges listing_scope into URLs alongside admin_context (independent from admin_view_scope).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesIndexAdminGodviewMergesListingScopeWithAdminContext(): void
    {
        $source = $this->readSource('templates/files/index.html.twig');

        self::assertStringContainsString("merge({'admin_context': '1', 'listing_scope': 'shared'})", $source);
        self::assertStringContainsString("merge({'admin_context': '1', 'listing_scope': 'owned'})", $source);
        self::assertStringContainsString("merge({'admin_context': '1', 'listing_scope': 'both'})", $source);
        self::assertStringContainsString('data-files-admin-view-scope="owner"', $source);
    }

    /**
     * @brief Toolbar keeps listing_scope links under Affichage even when user panes render (no duplicate suppression gate).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesIndexShowsToolbarListingScopeWithoutUseUserFilesPanesGate(): void
    {
        $source = $this->readSource('templates/files/index.html.twig');

        self::assertStringNotContainsString('{% if not useUserFilesPanes|default(false) %}', $source);
    }

    /**
     * @brief FilesController must not override parsed listing_scope when building admin godview criteria.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesControllerDoesNotForceOwnedListingScopeForAdminContext(): void
    {
        $source = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('$showLegacySharedSection = $useUserFilesPanes ? false : $showSharedListingSection;', $source);
    }

    /**
     * @brief Partial listing fetch JS syncs toolbar chrome after fragment swap.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testFilesSpaceJsSyncsToolbarAfterPartialFetch(): void
    {
        $source = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function syncFilesToolbarChrome', $source);
        self::assertStringContainsString('syncFilesToolbarChrome(next)', $source);
        self::assertStringContainsString("closest('a[data-files-listing-scope]')", $source);
        self::assertStringContainsString("closest('a[data-files-view-toggle]')", $source);
    }

    /**
     * @brief Admin godview scope toggle JS keeps listing_scope in URL state (orthogonal dimensions).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testFilesSpaceJsAdminScopeClickDoesNotDeleteListingScope(): void
    {
        $source = $this->readSource('public/js/files-space.js');
        $needle = "event.target.closest('a[data-files-admin-view-scope]')";
        $start = strpos($source, $needle);
        self::assertNotFalse($start, 'admin godview click handler must exist');
        $slice = substr($source, $start, 1400);
        self::assertStringNotContainsString('delete state.listing_scope', $slice);
        self::assertStringContainsString('delete state.view_scope', $slice);
        self::assertStringContainsString('delete state.subject_user', $slice);
        self::assertStringContainsString('state.owner = adminSid', $slice);
        self::assertStringContainsString('syncFilesToolbarChrome(state)', $slice);
    }
}
