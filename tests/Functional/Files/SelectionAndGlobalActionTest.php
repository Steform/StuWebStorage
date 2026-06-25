<?php

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 21–22: listing selection, global actions, decoupled public/friends share modals.
 * Static template inspection avoids booting the kernel for markup-level regression sentinels.
 */
class SelectionAndGlobalActionTest extends TestCase
{
    /**
     * @brief Read a template file from the repository and return its raw content.
     * @param string $relativePath Repo-relative path to the template.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function readTemplate(string $relativePath): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root.DIRECTORY_SEPARATOR.$relativePath;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @brief Listing fragment must expose a master checkbox plus a per-row checkbox in both layouts.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testListingFragmentExposesSelectionScopeAndCheckboxes(): void
    {
        $source = $this->readTemplate('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString('data-files-selection-scope="owned"', $source);
        self::assertStringContainsString('data-files-selection-scope="shared"', $source);
        self::assertStringContainsString('data-files-selection-group="owned"', $source);
        self::assertStringContainsString('data-files-selection-group="shared"', $source);
        self::assertStringContainsString('id="files-select-all"', $source);
        self::assertStringContainsString('id="files-select-all-shared"', $source);
        self::assertStringContainsString('data-files-select-all', $source);
        self::assertStringContainsString('data-files-select-all-scope="owned"', $source);
        self::assertStringContainsString('data-files-select-all-scope="shared"', $source);
        self::assertStringContainsString('data-files-select-id', $source);
        self::assertStringContainsString('data-files-select-scope="owned"', $source);
        self::assertStringContainsString('data-files-select-scope="shared"', $source);
        self::assertStringContainsString('id="files-select-grid-', $source);
        self::assertStringContainsString('id="files-select-row-', $source);
        self::assertStringContainsString('data-files-selection-live', $source);
        self::assertStringContainsString('files-grid-card-compact', $source);
        self::assertStringContainsString('files-grid-card-compact--chrome-less', $source);
        self::assertStringContainsString('card border-0', $source);
        self::assertStringContainsString('files-grid-card-compact__preview', $source);
        self::assertStringContainsString('data-files-row-target="{{ file.id }}"', $source);
        self::assertStringContainsString('title="{{ file.originalFileName }}"', $source);
        self::assertStringContainsString('id="files-sections-accordion"', $source);
        self::assertStringContainsString('data-files-section="my_files"', $source);
        self::assertStringContainsString('data-files-section="shared_for_me"', $source);
        self::assertStringContainsString('data-files-section-toggle="my_files"', $source);
        self::assertStringContainsString('data-files-section-toggle="shared_for_me"', $source);
        self::assertStringContainsString('aria-controls="{{ myFilesAccordionCollapseId }}"', $source);
        self::assertStringContainsString('aria-controls="{{ sharedAccordionCollapseId }}"', $source);
        self::assertStringContainsString('files-grid-card-compact__share-badges', $source);
        self::assertStringContainsString('bi bi-globe', $source);
        self::assertStringContainsString('bi bi-people-fill', $source);
        self::assertStringContainsString("'files.grid.badge.public_aria'|trans", $source);
        self::assertStringContainsString("'files.grid.badge.friends_aria'|trans", $source);
        self::assertStringContainsString("'files.section.my_files'|trans", $source);
        self::assertStringContainsString("'files.section.shared_for_me'|trans", $source);
        self::assertStringContainsString("'files.section.shared_for_me_empty'|trans", $source);
        self::assertStringContainsString("'files.folder.root'|trans", $source);
        self::assertStringContainsString('data-files-folder-open-url', $source);
        self::assertStringContainsString('files_folder_download_zip', $source);
        self::assertStringContainsString('data-files-folder-delete-open', $source);
        self::assertStringContainsString('data-files-folder-action="properties"', $source);
        self::assertStringContainsString('data-files-folder-action="share-public"', $source);
        self::assertStringContainsString('data-files-folder-action="share-friends"', $source);
        self::assertStringContainsString('folderPublicLandingUrls', $source);
        self::assertStringContainsString('currentFolderPublicLandingUrl', $source);
        self::assertStringContainsString('data-files-row-action="copy-public-link"', $source);
        self::assertStringNotContainsString('data-files-row-action="copy-public-link-with-password"', $source);
        self::assertStringContainsString('data-files-folder-action="copy-public-link-with-password"', $source);
        self::assertStringContainsString('data-files-folder-action="resolve-copy-public-link"', $source);
        self::assertStringContainsString('showSharedListingSection', $source);
    }

    /**
     * @brief Listing fragment must NOT inline the legacy grant form.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testListingFragmentDoesNotInlineLegacyGrantForms(): void
    {
        $source = $this->readTemplate('templates/files/_listing_fragment.html.twig');

        self::assertStringNotContainsString("path('files_grant'", $source);
        self::assertStringNotContainsString("path('files_revoke'", $source);
    }

    /**
     * @brief The index template must render a global Action dropdown with create-folder plus selection-scoped entries (rename modal wiring checked via include path because readTemplate does not expand Twig includes).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testIndexTemplateExposesGlobalActionDropdown(): void
    {
        $source = $this->readTemplate('templates/files/index.html.twig');

        self::assertStringContainsString('id="files-action-global-toggle"', $source);
        self::assertStringContainsString('data-files-action-global="create-folder"', $source);
        self::assertStringNotContainsString('data-files-action-global="share-public"', $source);
        self::assertStringNotContainsString('data-files-action-global="share-friends"', $source);
        self::assertStringContainsString('data-files-action-global="download-selection"', $source);
        self::assertStringContainsString('data-files-action-global="move-selection"', $source);
        self::assertStringContainsString('data-files-action-global="delete"', $source);
        self::assertStringContainsString('data-files-action-scope="owned"', $source);
        self::assertStringContainsString('data-files-action-scope="both"', $source);
        self::assertStringContainsString('data-files-action-requires-selection="1"', $source);
        self::assertDoesNotMatchRegularExpression(
            '/id="files-action-global-toggle"[^>]*\bdisabled\b/i',
            $source,
            'Global action toggle must stay available to open create-folder action'
        );
        self::assertStringContainsString('data-files-action-global-label', $source);
        self::assertStringContainsString("'files.toolbar.action_global'|trans", $source);
        self::assertStringContainsString("'files.action.create_folder'|trans", $source);
        self::assertStringContainsString('data-files-admin-owner-filter-block', $source);
        self::assertStringContainsString('id="files-admin-owner-search"', $source);
        self::assertStringContainsString('id="files-admin-owner-hidden"', $source);
        self::assertStringContainsString('name="admin_view_scope" value="owner"', $source);
        self::assertStringContainsString('data-files-admin-owner-filter-control', $source);
        self::assertStringContainsString('data-files-toast-close-label=', $source);
        self::assertStringContainsString('data-files-delete-toast-success-template=', $source);
        self::assertStringContainsString('data-files-delete-toast-partial-template=', $source);
        self::assertStringContainsString('data-files-delete-toast-error=', $source);
        self::assertStringContainsString('data-files-move-toast-success-template=', $source);
        self::assertStringContainsString('data-files-move-toast-partial-template=', $source);
        self::assertStringContainsString('data-files-move-toast-error=', $source);
        self::assertStringContainsString('data-files-download-url-template=', $source);
        self::assertStringContainsString('filesCreateFolderModal', $source);
        self::assertStringContainsString("files/_move_bulk_modal.html.twig", $source);
        self::assertStringContainsString("files/_rename_modal.html.twig", $source);
        self::assertStringContainsString('filesDeleteFolderModal', $source);
        self::assertStringContainsString("files/_folder_properties_modal.html.twig", $source);
        self::assertStringContainsString("path('files_folder_create')", $source);
    }

    /**
     * @brief The index template must include the public and friends share modals (not the legacy unified modal).
     * @param void No input parameter.
     * @return void
     * @date 2026-04-30
     * @author Stephane H.
     */
    public function testIndexTemplateIncludesDecoupledShareModals(): void
    {
        $source = $this->readTemplate('templates/files/index.html.twig');

        self::assertSame(1, substr_count($source, "files/_share_public_modal.html.twig"));
        self::assertSame(1, substr_count($source, "files/_share_friends_modal.html.twig"));
        self::assertSame(1, substr_count($source, "files/_delete_bulk_modal.html.twig"));
        self::assertStringNotContainsString("files/_share_modal.html.twig", $source);
    }

    /**
     * @brief Bulk delete modal must post folder_ids[] alongside file ids for mixed selection.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testDeleteBulkModalIncludesFolderIdContainer(): void
    {
        $source = $this->readTemplate('templates/files/_delete_bulk_modal.html.twig');
        self::assertStringContainsString('data-files-delete-bulk-folder-ids', $source);
    }

    /**
     * @brief The public share modal must expose state URL, single/bulk endpoints and CSRF hooks.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testSharePublicModalExposesHooks(): void
    {
        $source = $this->readTemplate('templates/files/_share_public_modal.html.twig');

        self::assertStringContainsString('id="filesSharePublicModal"', $source);
        self::assertStringContainsString('data-files-share-state-url-template', $source);
        self::assertStringContainsString('data-files-folder-share-state-url-template', $source);
        self::assertStringContainsString('data-files-share-public-single-url-template', $source);
        self::assertStringContainsString('data-files-share-public-password-toggle-single-url-template', $source);
        self::assertStringContainsString('data-files-share-public-password-toggle-folder-url-template', $source);
        self::assertStringContainsString('data-files-share-public-bulk-url', $source);
        self::assertStringContainsString('data-files-share-public-csrf', $source);
        self::assertStringContainsString('data-files-share-public-bulk-csrf', $source);
        self::assertStringContainsString('id="files-share-public-enabled"', $source);
        self::assertStringContainsString('id="files-share-public-password-enabled"', $source);
        self::assertStringContainsString('data-files-share-public-password-display', $source);
        self::assertStringContainsString('name="public_expires_at"', $source);
        self::assertStringContainsString("'files.share.public.expires_hint'", $source);
    }

    /**
     * @brief Files space script must expose immediate password toggle flow and copy-link-with-password query composition.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function testFilesSpaceJsExposesPasswordToggleAndCopyWithPasswordHooks(): void
    {
        $source = $this->readTemplate('public/js/files-space.js');

        self::assertStringContainsString('function submitPublicPasswordToggle(formEl, pwdToggle)', $source);
        self::assertStringContainsString('data-files-password-toggle-busy', $source);
        self::assertStringContainsString('filesSharePublicPasswordToggleSingleUrlTemplate', $source);
        self::assertStringContainsString('filesSharePublicPasswordToggleFolderUrlTemplate', $source);
        self::assertStringContainsString("params.set('public_password_enabled', desiredState ? '1' : '0');", $source);
        self::assertStringContainsString("share_password=' + encodeURIComponent(pl)", $source);
        self::assertStringContainsString("share_password=' + encodeURIComponent(plf)", $source);
        self::assertStringContainsString("params.append('folder_ids[]', String(id));", $source);
        self::assertStringContainsString("params.set('admin_context', '1');", $source);
        self::assertStringContainsString("params.set('admin_view_scope', 'all');", $source);
        self::assertStringContainsString('downloadSharedSelection(ids, folderIds, {', $source);
        self::assertStringContainsString('function closeAllActionMenus()', $source);
        self::assertStringContainsString('if (anyRowAction || anyFolderCopyPwdAction) {', $source);
        self::assertStringContainsString("closest('[data-files-folder-action]')", $source);
        self::assertStringContainsString("closest('[data-files-row-action=\"properties\"]')", $source);
        self::assertStringContainsString('pack.json && pack.json.message', $source);
        self::assertStringContainsString('modalRoot.dataset.msgSubmitError', $source);
    }

    /**
     * @brief The friends share modal must expose grantee search and bulk replace hook.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testShareFriendsModalExposesHooks(): void
    {
        $source = $this->readTemplate('templates/files/_share_friends_modal.html.twig');

        self::assertStringContainsString('id="filesShareFriendsModal"', $source);
        self::assertStringContainsString('data-files-share-friends-bulk-url', $source);
        self::assertStringContainsString('data-files-share-friends-bulk-csrf', $source);
        self::assertStringContainsString('data-files-share-friends-bulk-only', $source);
        self::assertStringContainsString('name="replace_existing"', $source);
        self::assertStringContainsString('id="files-share-friends-grantee-ids"', $source);
    }

    /**
     * @brief Per-row dropdown must offer share-public and share-friends actions.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testFileActionsDropdownHasDecoupledShareActions(): void
    {
        $source = $this->readTemplate('templates/files/_file_actions_dropdown.html.twig');

        self::assertStringContainsString('data-files-row-action="share-public"', $source);
        self::assertStringContainsString('data-files-row-action="share-friends"', $source);
        self::assertStringContainsString('data-files-row-id=', $source);
        self::assertStringContainsString("'files.action.share_public'|trans", $source);
        self::assertStringContainsString("'files.action.share_friends'|trans", $source);
        self::assertStringContainsString('data-files-row-action="rename-open"', $source);
        self::assertStringContainsString("'files.action.rename'|trans", $source);
        self::assertStringNotContainsString("path('files_visibility'", $source);
        self::assertStringNotContainsString("path('files_grant'", $source);
    }

    /**
     * @brief Shared-for-me dropdown must expose only non-mutating actions.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testSharedForMeDropdownHasOnlyNonMutatingActions(): void
    {
        $source = $this->readTemplate('templates/files/_file_actions_dropdown_shared_for_me.html.twig');

        self::assertStringContainsString("'files.action.download'|trans", $source);
        self::assertStringContainsString("path('files_download'", $source);
        self::assertStringContainsString("'files.action.properties'|trans", $source);
        self::assertStringNotContainsString("'files.action.share_public'|trans", $source);
        self::assertStringNotContainsString("'files.action.share_friends'|trans", $source);
        self::assertStringNotContainsString("'files.action.delete'|trans", $source);
        self::assertStringNotContainsString("'files.action.rename'|trans", $source);
    }

    /**
     * @brief Owned-folder dropdown markup must expose rename-open twice (grid + list); shared-folder menus must not.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testListingFragmentOwnedFoldersExposeRenameAndSharedFoldersDoNot(): void
    {
        $source = $this->readTemplate('templates/files/_listing_fragment.html.twig');

        self::assertSame(2, substr_count($source, 'data-files-folder-action="rename-open"'), 'Owned folder menus must declare rename-open exactly in grid and table blocks.');
        self::assertStringContainsString("'files.folder.action.rename'|trans", $source);
        $sharedGridSectionStart = strpos($source, '{% if sharedForMeFolders is not empty %}');
        self::assertNotFalse($sharedGridSectionStart);
        $fromSharedForMeGrid = substr($source, $sharedGridSectionStart);
        self::assertStringNotContainsString(
            'data-files-folder-action="rename-open"',
            $fromSharedForMeGrid,
            'Shared-for-me folder grid and following markup must not offer folder rename.'
        );
        $sharedListSlice = (string) strstr($source, 'aria-labelledby="files-shared-folder-actions-{{ sharedFolder.id }}"');
        self::assertNotFalse($sharedListSlice);
        self::assertStringNotContainsString(
            'data-files-folder-action="rename-open"',
            $sharedListSlice,
            'Shared-with-me folder list dropdown must not offer rename.'
        );
    }

    /**
     * @brief Shared-for-me section in listing fragment must use dedicated dropdown component.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function testListingFragmentUsesSharedForMeDropdownComponent(): void
    {
        $source = $this->readTemplate('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString("files/_file_actions_dropdown_shared_for_me.html.twig", $source);
        self::assertStringNotContainsString("btn btn-outline-secondary btn-sm\" href=\"{{ path('files_download'", $source);
    }

    /**
     * @brief Shared-for-me list empty row must keep a 5-column colspan after column reduction.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-30
     * @author Stephane H.
     */
    public function testSharedForMeListEmptyStateUsesFiveColumnSpan(): void
    {
        $source = $this->readTemplate('templates/files/_listing_fragment.html.twig');

        self::assertStringContainsString('colspan="6"', $source);
        self::assertStringContainsString('files.section.shared_for_me_empty', $source);
        self::assertStringContainsString('files.listing_scope.empty_shared', $source);
    }

    /**
     * @brief Listing row action menus must use an inner scroll panel; CSS must target only files-actions-dropdown under the table wrap; contextmenu removes inner scroll.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function testListingActionDropdownsUseInnerScrollShellAndCssContract(): void
    {
        $scrollOpen = $this->readTemplate('templates/files/_files_actions_dropdown_scroll_open.html.twig');
        self::assertStringContainsString('files-dropdown-menu-scroll', $scrollOpen);

        $dropdownOwned = $this->readTemplate('templates/files/_file_actions_dropdown.html.twig');
        self::assertStringContainsString('_files_actions_dropdown_scroll_open.html.twig', $dropdownOwned);

        $listing = $this->readTemplate('templates/files/_listing_fragment.html.twig');
        self::assertGreaterThanOrEqual(4, substr_count($listing, '_files_actions_dropdown_scroll_open.html.twig'));

        $css = $this->readTemplate('public/css/files-space.css');
        self::assertStringContainsString('.files-listing-table-wrap .files-actions-dropdown > .dropdown-menu', $css);
        self::assertStringContainsString('.files-actions-dropdown .files-dropdown-menu-scroll', $css);
        self::assertStringContainsString('.dropdown-menu.files-row-context-menu .files-dropdown-menu-scroll', $css);
        self::assertMatchesRegularExpression(
            '/\.dropdown-menu\.files-row-context-menu\s+\.files-dropdown-menu-scroll\s*\{[^}]*max-height\s*:\s*none/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.dropdown-menu\.files-row-context-menu\s+\.files-dropdown-menu-scroll\s*\{[^}]*overflow\s*:\s*visible/s',
            $css
        );
        self::assertStringNotContainsString('.files-listing-table-wrap .dropdown-menu {', $css);

        $index = $this->readTemplate('templates/files/index.html.twig');
        self::assertStringContainsString("asset('css/files-space.css') }}?v=20260504-pane-accordion", $index);
    }

    /**
     * @brief Grid CSS must hide action toggle button and move shadow to preview elements.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testGridCssUsesChromeLessCardsAndPreviewShadow(): void
    {
        $source = $this->readTemplate('public/css/files-space.css');

        self::assertStringContainsString('.files-grid-card.files-grid-card-compact.files-grid-card-compact--chrome-less', $source);
        self::assertStringContainsString('.files-grid-card-compact .files-actions-dropdown > .dropdown-toggle', $source);
        self::assertStringContainsString('.files-grid-card-compact .files-grid-card-compact__preview-image', $source);
        self::assertStringContainsString('.files-grid-card-compact .files-type-icon--grid', $source);
        self::assertStringContainsString('.files-grid-card-compact .files-grid-card-compact__share-badges', $source);
        self::assertStringContainsString('pointer-events: none;', $source);
    }

    /**
     * @brief All five locales must declare decoupled share and table column keys.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testRequiredI18nKeysExistInAllLocales(): void
    {
        $requiredKeys = [
            'files.toolbar.action_global',
            'files.section.my_files',
            'files.section.shared_for_me',
            'files.section.shared_for_me_empty',
            'files.grid.open_actions_aria',
            'files.grid.badge.public_aria',
            'files.grid.badge.friends_aria',
            'files.action.share_public',
            'files.action.share_friends',
            'files.action.download_selection',
            'files.action.rename',
            'files.folder.action.rename',
            'files.folder.rename.modal_title',
            'files.action.delete_selection',
            'files.action.menu.global_aria',
            'files.rename.modal_title',
            'files.rename.name_label',
            'files.rename.submit',
            'files.rename.cancel',
            'files.rename.modal_error',
            'files.delete_bulk.title',
            'files.delete_bulk.message',
            'files.delete_bulk.count_suffix',
            'files.delete_bulk.more_template',
            'files.delete_bulk.toast_success',
            'files.delete_bulk.toast_partial',
            'files.delete_bulk.toast_error',
            'files.delete_bulk.cancel',
            'files.delete_bulk.confirm',
            'files.delete_bulk.close_aria',
            'files.table.share_public',
            'files.table.share_friends',
            'files.share.public.modal_title_single',
            'files.folder.share.public.modal_title_single',
            'files.share.public.expires_label',
            'files.share.friends.modal_title_single',
            'files.folder.share.friends.modal_title_single',
            'files.folder.share.friends.friends_expiration_mixed',
            'files.folder.share.friends.friends_grant_expired',
            'files.share.friends.grants_label',
            'files.share.yes',
            'files.share.no',
            'files.share.expiration_active_aria',
            'files.flash.bulk_delete_ok',
            'files.flash.bulk_delete_partial',
            'files.flash.rename_name_required',
            'files.flash.rename_name_too_long',
            'files.flash.rename_unchanged',
            'files.flash.renamed',
            'files.flash.folder_rename_name_required',
            'files.flash.folder_rename_name_too_long',
            'files.flash.folder_rename_unchanged',
            'files.flash.folder_rename_name_conflict',
            'files.flash.folder_renamed',
            'files.flash.upload_name_conflict',
            'files.flash.name_conflict_same_level',
            'files.folder.flash.name_conflict_with_file',
            'files.list.folder_empty',
            'files.properties.public_status',
            'files.properties.edit_public',
            'files.properties.edit_friends',
            'files.folder.properties.title',
            'files.folder.properties.total_size',
            'files.folder.properties.total_files',
            'files.folder.properties.total_subfolders',
            'files.folder.properties.error',
            'files.selection.aria_select_all',
            'files.selection.aria_select_row',
            'files.selection.live_count',
        ];
        $locales = ['fr', 'en', 'de', 'lt', 'no'];

        foreach ($locales as $locale) {
            $source = $this->readTemplate('translations/messages.'.$locale.'.yaml');
            self::assertNotSame('', $source, 'Locale file must be readable: '.$locale);
            foreach ($requiredKeys as $key) {
                $needle = $this->extractLeafKey($key);
                self::assertStringContainsString(
                    $needle.':',
                    $source,
                    sprintf('Locale "%s" is missing translation leaf for "%s"', $locale, $key)
                );
            }
        }
    }

    /**
     * @brief Extract the leaf key fragment of a dotted translation key for naive YAML inspection.
     * @param string $dottedKey Full dotted translation key.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function extractLeafKey(string $dottedKey): string
    {
        $segments = explode('.', $dottedKey);

        return (string) end($segments);
    }
}
