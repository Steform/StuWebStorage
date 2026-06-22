<?php

namespace App\Tests\Functional\Files;

use App\Entity\SharedFile;
use App\File\SharedFileOwnerListCriteria;
use App\Repository\SharedFileRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Regression coverage for Sprint 16–17 listing criteria and helpers.
 */
class SharedFileListingSupportTest extends KernelTestCase
{
    /**
     * @brief Skip repository integration checks when the configured database is unreachable (local CI without MySQL).
     * @param void No input parameter.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function skipIfDatabaseUnavailable(): void
    {
        try {
            self::bootKernel();
            static::getContainer()->get('doctrine.dbal.default_connection')->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable for repository integration test: '.$e->getMessage());
        }
    }

    /**
     * @brief Verify extension normalization strips unsafe characters.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testNormalizeFileExtensionExtractsSuffix(): void
    {
        self::assertSame('pdf', SharedFile::normalizeFileExtension('Report.pdf'));
        self::assertSame('gz', SharedFile::normalizeFileExtension('archive.tar.gz'));
        self::assertSame('', SharedFile::normalizeFileExtension('no-extension'));
        self::assertSame('', SharedFile::normalizeFileExtension(''));
    }

    /**
     * @brief Verify criteria serialization carries core filters for URLs.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testOwnerListCriteriaToQueryParamsIncludesSortAndExtensions(): void
    {
        $criteria = new SharedFileOwnerListCriteria(
            searchQuery: 'notes',
            sortField: 'name',
            sortDirection: 'asc',
            filterPublic: 'yes',
            extensionFilters: ['pdf', 'txt'],
            view: 'grid',
        );
        $params = $criteria->toQueryParams();
        self::assertSame('notes', $params['q']);
        self::assertSame('name', $params['sort']);
        self::assertSame('asc', $params['dir']);
        self::assertSame('yes', $params['filter_public']);
        self::assertSame(['pdf', 'txt'], $params['ext']);
        self::assertSame('grid', $params['view']);
    }

    /**
     * @brief Verify neutral sort state omits sort and dir query params.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function testOwnerListCriteriaToQueryParamsOmitsSortWhenNeutral(): void
    {
        $criteria = new SharedFileOwnerListCriteria(
            searchQuery: 'neutral',
            sortField: '',
            sortDirection: '',
        );
        $params = $criteria->toQueryParams();

        self::assertSame('neutral', $params['q']);
        self::assertArrayNotHasKey('sort', $params);
        self::assertArrayNotHasKey('dir', $params);
    }

    /**
     * @brief Listing scope serializes for owned and shared-only URLs; default both omits key.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function testOwnerListCriteriaToQueryParamsIncludesListingScopeWhenNotBoth(): void
    {
        $owned = new SharedFileOwnerListCriteria(listingScope: 'owned');
        self::assertSame('owned', $owned->toQueryParams()['listing_scope']);

        $shared = new SharedFileOwnerListCriteria(listingScope: 'shared');
        self::assertSame('shared', $shared->toQueryParams()['listing_scope']);

        $both = new SharedFileOwnerListCriteria(listingScope: 'both');
        self::assertArrayNotHasKey('listing_scope', $both->toQueryParams());
    }

    /**
     * @brief Verify Sprint 17 advanced params serialize for bookmarkable URLs.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testOwnerListCriteriaToQueryParamsIncludesAdvancedFilters(): void
    {
        $after = new DateTimeImmutable('2026-01-01T10:00:00');
        $criteria = new SharedFileOwnerListCriteria(
            filterHasGrant: 'yes',
            granteeUserIds: [12, 34],
            uploadedAfter: $after,
            expiresBefore: new DateTimeImmutable('2026-12-31T23:59:00'),
        );
        $params = $criteria->toQueryParams();
        self::assertSame('yes', $params['filter_has_grant']);
        self::assertSame([12, 34], $params['grantee']);
        self::assertSame($after->format('Y-m-d\TH:i'), $params['uploaded_after']);
        self::assertArrayHasKey('expires_before', $params);
    }

    /**
     * @brief Ensure filtered repository queries compile against Doctrine (requires migrated schema); includes full-list query without paging (Sprint 19).
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testOwnedFilteredQueriesExecute(): void
    {
        $this->skipIfDatabaseUnavailable();
        /** @var SharedFileRepository $repository */
        $repository = static::getContainer()->get(SharedFileRepository::class);

        $criteria = new SharedFileOwnerListCriteria(
            searchQuery: 'alpha',
            sortField: 'modified',
            sortDirection: 'desc',
            extensionFilters: ['pdf'],
        );
        $ownerId = 919191919;

        $rows = $repository->findOwnedPageFiltered($ownerId, $criteria, 1, 5);
        self::assertIsArray($rows);

        $allRows = $repository->findOwnedFilteredAll($ownerId, $criteria);
        self::assertIsArray($allRows);

        $total = $repository->countOwnedFiltered($ownerId, $criteria);
        self::assertGreaterThanOrEqual(0, $total);

        $ext = $repository->findDistinctExtensionsByOwner($ownerId);
        self::assertIsArray($ext);

        $sharedForMe = $repository->findSharedForGranteeAll($ownerId);
        self::assertIsArray($sharedForMe);
    }

    /**
     * @brief Ensure Sprint 17 EXISTS filters compile on the repository.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testOwnedFilteredWithGrantAndDatePredicatesExecutes(): void
    {
        $this->skipIfDatabaseUnavailable();
        /** @var SharedFileRepository $repository */
        $repository = static::getContainer()->get(SharedFileRepository::class);

        $criteria = new SharedFileOwnerListCriteria(
            filterHasGrant: 'yes',
            granteeUserIds: [999001],
            uploadedAfter: new DateTimeImmutable('2020-01-01T00:00:00'),
            updatedBefore: new DateTimeImmutable('2030-01-01T00:00:00'),
        );

        $ownerId = 919191919;
        $rows = $repository->findOwnedPageFiltered($ownerId, $criteria, 1, 5);
        self::assertIsArray($rows);
        $total = $repository->countOwnedFiltered($ownerId, $criteria);
        self::assertGreaterThanOrEqual(0, $total);
    }

    /**
     * @brief Ensure listing fragment template renders for live search partial responses without pagination variables (Sprint 19) and a single name column (Sprint 20).
     * @param void No input parameter.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function testListingFragmentTwigRendersEmptyState(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        $criteria = new SharedFileOwnerListCriteria();
        $html = $twig->render('files/_listing_fragment.html.twig', [
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
            'listingScope' => 'both',
            'showOwnedListingSection' => true,
            'showSharedListingSection' => true,
            'listingQueryResetAdvanced' => [],
            'currentLocale' => 'fr',
            'csrfDelete' => 'files_delete',
            'csrfVisibility' => 'files_visibility',
            'csrfGrant' => 'files_grant',
            'csrfRevoke' => 'files_revoke',
        ]);

        self::assertStringContainsString('files-listing-table-wrap', $html);
        self::assertStringNotContainsString('pagination', $html);
        self::assertStringNotContainsString('files.table.extension', $html);
    }
}
