<?php

declare(strict_types=1);

namespace App\Tests\Functional\Files;

use App\File\SharedFileOwnerListCriteria;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * @brief Behavioral rendering tests for shared section visibility in admin listing scope.
 * @date 2026-05-07
 * @author Stephane H.
 */
final class AdminListingScopeSharedVisibilityTest extends KernelTestCase
{
    /**
     * @brief Build a minimal Twig context for files listing fragment rendering.
     * @param bool $showSharedListingSection Whether shared section flag is enabled.
     * @param bool $useUserFilesPanes Whether admin all-users panes mode is enabled.
     * @return array<string, mixed>
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function buildContext(bool $showSharedListingSection, bool $useUserFilesPanes): array
    {
        $criteria = new SharedFileOwnerListCriteria(listingScope: $showSharedListingSection ? 'shared' : 'owned');

        return [
            'files' => [],
            'folders' => [],
            'folderShareStates' => [],
            'folderSizeBytes' => [],
            'folderPublicLandingUrls' => [],
            'currentFolderPublicLandingUrl' => null,
            'currentFolderShareState' => null,
            'currentFolder' => null,
            'sharedForMeFiles' => [],
            'sharedForMeFolders' => [],
            'sharedBreadcrumbFolders' => [],
            'sharedFolderSizeBytes' => [],
            'sharedForMeCurrentFolderId' => 0,
            'total' => 0,
            'layoutView' => 'list',
            'listingQuery' => $criteria->toQueryParams(),
            'listingCriteria' => $criteria,
            'grantMaps' => [],
            'listingChips' => [],
            'hasAdvancedFilters' => false,
            'hasActiveCriteria' => false,
            'isCurrentFolderCompletelyEmpty' => true,
            'listingScope' => $showSharedListingSection ? 'shared' : 'owned',
            'showOwnedListingSection' => !$showSharedListingSection,
            'showSharedListingSection' => $showSharedListingSection,
            'listingQueryResetAdvanced' => [],
            'currentLocale' => 'fr',
            'csrfDelete' => 'files_delete',
            'csrfVisibility' => 'files_visibility',
            'csrfGrant' => 'files_grant',
            'csrfRevoke' => 'files_revoke',
            'useUserFilesPanes' => $useUserFilesPanes,
            'adminContext' => true,
        ];
    }

    /**
     * @brief Shared section renders in admin my-files mode when listing scope is shared.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testSharedSectionRendersWhenNotInPanesMode(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        $html = $twig->render('files/_listing_fragment.html.twig', $this->buildContext(true, false));

        self::assertStringContainsString('data-files-section="shared_for_me"', $html);
    }

    /**
     * @brief Shared legacy section is hidden when admin all-users panes mode is active.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function testSharedSectionIsHiddenWhenPanesModeIsActive(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        $html = $twig->render('files/_listing_fragment.html.twig', $this->buildContext(true, true));

        self::assertStringNotContainsString('data-files-section="shared_for_me"', $html);
        self::assertStringNotContainsString('files.section.shared_for_me_empty', $html);
    }
}
