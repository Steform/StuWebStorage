<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use PHPUnit\Framework\TestCase;

/**
 * @brief Contract checks for admin godview scope resolver and toolbar UX wiring.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class AdminGodviewScopeUxContractTest extends TestCase
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
     * @brief Resolver and controller must propagate admin_view_scope and owner_query params.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testScopeContractIsPresentInResolverAndController(): void
    {
        $resolver = $this->readSource('src/Service/File/FilesQueryScopeResolver.php');
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString("request->query->get('admin_view_scope'", $resolver);
        self::assertStringContainsString("'viewScope'", $resolver);
        self::assertStringContainsString("'ownerQuery'", $resolver);
        self::assertStringContainsString("'admin_view_scope'", $controller);
        self::assertStringContainsString("'owner_query'", $controller);
    }

    /**
     * @brief Files frontend JS must handle admin view scope toggles and owner suggest endpoint.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesSpaceJsSupportsAdminScopeAndOwnerSuggest(): void
    {
        $js = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('data-files-admin-view-scope', $js);
        self::assertStringContainsString('admin_view_scope', $js);
        self::assertStringContainsString('data-files-admin-owner-suggest-url', $js);
        self::assertStringContainsString('files-admin-owner-search', $js);
    }

    /**
     * @brief Resolver must reconcile admin_view_scope=all with stale view_scope=me for multi-pane godview.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testResolverDocumentsAdminAllOverridesStaleViewScope(): void
    {
        $resolver = $this->readSource('src/Service/File/FilesQueryScopeResolver.php');

        self::assertStringContainsString('explicitUserDrilldown', $resolver);
        self::assertStringContainsString("\$viewScope === 'all'", $resolver);
    }

    /**
     * @brief Files index must force view_scope=all when linking to admin "all users" scope.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesIndexAdminAllUsersLinkSetsViewScopeAll(): void
    {
        $twig = $this->readSource('templates/files/index.html.twig');

        self::assertStringContainsString("'view_scope': 'all'", $twig);
        self::assertStringContainsString("'subject_user': null", $twig);
    }

    /**
     * @brief Files JS must normalize view_scope and refresh admin banner when switching to admin all-users scope.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testFilesSpaceJsNormalizesViewScopeWhenAdminGodviewAll(): void
    {
        $js = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("state.view_scope = 'all'", $js);
        self::assertStringContainsString('delete state.subject_user', $js);
        self::assertStringContainsString('syncAdminGodviewChromeAfterToolbar', $js);
    }

    /**
     * @brief Admin banner and modals must expose stable data attributes and always render target owner fields (hidden when inactive).
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testTwigAdminBannerHasDataAttributesAndTargetOwnerBlocks(): void
    {
        $twig = $this->readSource('templates/files/index.html.twig');

        self::assertStringContainsString('data-files-admin-owner-filter-block', $twig);
        self::assertStringContainsString('name="target_owner_user_id"', $twig);
    }

    /**
     * @brief Admin files index must optionally 302 to a canonical query when all-users scope carries stale params.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminIndexCanonicalizesStaleAdminAllUsersQuery(): void
    {
        $src = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('maybeRedirectAdminFilesToCanonicalAllUsersQuery', $src);
        self::assertStringContainsString('adminFilesAreListingQueriesEqual', $src);
        self::assertStringContainsString("redirectToRoute('admin_files_index'", $src);
    }

    /**
     * @brief Bare admin files entry must restore last godview scope from session or fallback all-users.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminIndexRestoresGodviewScopeFromSessionMemory(): void
    {
        $src = $this->readSource('src/Controller/FilesController.php');
        $svc = $this->readSource('src/Service/Admin/AdminGodviewSessionStateService.php');

        self::assertStringContainsString('maybeRedirectAdminGodviewFromSessionMemory', $src);
        self::assertStringContainsString('persistAdminGodviewStateFromViewData', $src);
        self::assertStringContainsString('admin_godview.last_state', $svc);
    }

    /**
     * @brief Admin owner resolve route and repository helpers must exist for godview target owner UX.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminOwnerResolveContract(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');
        $repo = $this->readSource('src/Repository/UserRepository.php');
        $twig = $this->readSource('templates/files/index.html.twig');
        $js = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("admin_files_owner_resolve", $controller);
        self::assertStringContainsString('findActiveUsersMatchingExactPseudo', $repo);
        self::assertStringContainsString('extractPseudoSegmentForAdminOwnerResolve', $repo);
        self::assertStringContainsString('parseAdminOwnerSearchTokens', $repo);
        self::assertStringContainsString('data-files-admin-owner-resolve-url', $twig);
        self::assertStringContainsString('files-target-owner-i18n', $twig);
        self::assertStringContainsString('data-msg-target-owner-ambiguous', $twig);
        self::assertStringContainsString('resolveTargetOwnerFromServer', $js);
        self::assertStringContainsString('data-files-admin-owner-resolve-url', $js);
        self::assertStringContainsString('AppFlashToasts', $js);
    }

    /**
     * @brief Admin scope badge must expose server-side scope label for JS fallback; live region exposes session user id.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testAdminBannerScopeLabelDatasetAndSessionUserId(): void
    {
        $twig = $this->readSource('templates/files/index.html.twig');
        $js = $this->readSource('public/js/files-space.js');
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('data-files-admin-scope-badge', $twig);
        self::assertStringContainsString('data-files-admin-scope-badge-label', $twig);
        self::assertStringContainsString('adminScopeBannerLabel', $twig);
        self::assertStringContainsString('data-files-admin-session-user-id', $twig);
        self::assertStringContainsString('preserveAdminListingKeys', $js);
        self::assertStringContainsString('lastValidAdminScopeLabel', $js);
        self::assertStringContainsString('initialFilesListingQuery', $js);
        self::assertStringContainsString('resolveAdminScopeBannerLabel', $controller);
        self::assertStringContainsString('formatUserDisplayLabelForAdminBanner', $controller);
        self::assertStringContainsString('adminOwnerFallbackNotice', $controller);
    }

    /**
     * @brief Admin JS must preserve admin_context/admin_view_scope on admin route and update scope badge with stable fallbacks.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testAdminJsPreservesContextAndUsesStableBannerFallbacks(): void
    {
        $js = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString("var isAdminRoute = !!document.querySelector('.files-space-page--admin-files-route');", $js);
        self::assertStringContainsString("target.admin_context = '1';", $js);
        self::assertStringContainsString("target.admin_view_scope = 'owner';", $js);
        self::assertStringContainsString('ownerLabel = fallbackScopeLabel;', $js);
        self::assertStringContainsString('ownerLabel = scopeAll;', $js);
        self::assertStringContainsString('scopeBadgeLabelEl.textContent = ownerLabel;', $js);
    }

    /**
     * @brief Admin display toolbar and scope badge must include responsive safety hooks for submenu overflow and wrapping.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testAdminTwigAndCssExposeResponsiveBannerAndScopeHooks(): void
    {
        $twig = $this->readSource('templates/files/index.html.twig');
        $css = $this->readSource('public/css/files-space.css');

        self::assertStringNotContainsString('data-files-admin-banner', $twig);
        self::assertStringContainsString('files-admin-scope-badge', $twig);
        self::assertStringContainsString('files-display-submenu--scope', $twig);
        self::assertStringContainsString('class="d-flex flex-wrap align-items-center gap-2', $twig);
        self::assertStringContainsString('files-toolbar-second-row > *', $css);
        self::assertStringContainsString('.files-admin-scope-badge', $css);
    }

    /**
     * @brief Modal retain hidden fields must be synchronized from listing state in admin JS flows.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testAdminJsSyncsModalRetainStateForUploadAndFolderForms(): void
    {
        $js = $this->readSource('public/js/files-space.js');

        self::assertStringContainsString('function syncModalRetainStateFromListing(state)', $js);
        self::assertStringContainsString("formEl.querySelector('input[name=\"_retain_admin_context\"]')", $js);
        self::assertStringContainsString('syncModalRetainStateFromListing(next);', $js);
        self::assertStringContainsString('syncModalRetainStateFromListing(state);', $js);
        self::assertStringContainsString('syncModalRetainStateFromListing(readListingState());', $js);
    }

    /**
     * @brief Upload JSON responses must translate with request locale to avoid fallback language drift.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testUploadJsonTranslationsUseRequestLocale(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('$locale = (string) $request->getLocale();', $controller);
        self::assertStringContainsString("\$translator->trans(\$messageKey, [], 'messages', \$locale)", $controller);
        self::assertStringContainsString("\$translator->trans('files.flash.uploaded', [], 'messages', \$locale)", $controller);
    }

    /**
     * @brief Admin scope label formatter must not include email fragments in owner scope labels.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testAdminScopeFormatterIsPseudoOnlyWithIdFallback(): void
    {
        $controller = $this->readSource('src/Controller/FilesController.php');

        self::assertStringContainsString('formatUserDisplayLabelForAdminBanner', $controller);
        self::assertStringContainsString('return $pseudo;', $controller);
        self::assertStringNotContainsString("return \$pseudo.' ('.\$email.')';", $controller);
        self::assertStringNotContainsString('return $email;', $controller);
    }

    /**
     * @brief Fallback notice translation must use id and label placeholders.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function testOwnerFallbackNoticeTranslationHasPlaceholders(): void
    {
        foreach (['translations/messages.fr.yaml', 'translations/messages.en.yaml'] as $rel) {
            $raw = $this->readSource($rel);
            self::assertStringContainsString('owner_fallback_notice:', $raw);
            self::assertStringContainsString('%id%', $raw);
            self::assertStringContainsString('%label%', $raw);
        }
    }
}

