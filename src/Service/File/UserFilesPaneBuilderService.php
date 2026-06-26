<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Dto\File\FilesPageViewModel;
use App\Dto\File\UserFilesCapabilities;
use App\Dto\File\UserFilesPaneViewModel;
use App\Dto\File\UsersPanePagination;
use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Entity\User;
use App\File\SharedFileOwnerListCriteria;
use App\Repository\FolderRepository;
use App\Repository\ShareGrantRepository;
use App\Repository\SharedFileRepository;
use App\Repository\UserRepository;
use App\Service\Share\FolderPropertiesService;
use App\Service\Share\FolderPublicTokenService;
use App\Service\Share\FolderTreeService;
use App\Service\Share\SharedForMeTreeService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @brief Build UserFilesPane view models and FilesPageViewModel for files index (user + admin godview).
 * @details Admin all-users (`buildAdminAllUsersPage`) builds hierarchical owned trees per subject (`flatOwnedListing` false) and reads folder cursors via `useNamespacedFolderQueryKeys` (`uf_*` / `sf_*`). Single-subject paths use global `folder` and `shared_folder` query parameters.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class UserFilesPaneBuilderService
{
    /**
     * @brief Build pane builder service dependencies.
     * @param SharedFileRepository $sharedFileRepository Shared file repository.
     * @param FolderRepository $folderRepository Folder repository.
     * @param ShareGrantRepository $shareGrantRepository Share grant repository.
     * @param UserRepository $userRepository User repository.
     * @param FolderTreeService $folderTreeService Folder tree service.
     * @param FolderPropertiesService $folderPropertiesService Folder properties service.
     * @param FolderPublicTokenService $folderPublicTokenService Folder public token service.
     * @param UrlGeneratorInterface $urlGenerator URL generator.
     * @return void
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly UserRepository $userRepository,
        private readonly FolderTreeService $folderTreeService,
        private readonly FolderPropertiesService $folderPropertiesService,
        private readonly FolderPublicTokenService $folderPublicTokenService,
        private readonly SharedForMeTreeService $sharedForMeTreeService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @brief Resolve allowed page sizes for admin user-list pagination.
     * @param void No input parameter.
     * @return list<int>
     * @date 2026-05-04
     * @author Stephane H.
     */
    public static function allowedUsersPageSizes(): array
    {
        return [20, 50, 100, 200];
    }

    /**
     * @brief Parse users pagination query params with clamping.
     * @param Request $request HTTP request.
     * @return array{page: int, pageSize: int, sortField: string, sortDirection: string}
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function parseUsersPaginationParams(Request $request): array
    {
        $page = max(1, (int) $request->query->get('users_page', 1));
        $rawSize = (int) $request->query->get('users_page_size', 20);
        $allowed = self::allowedUsersPageSizes();
        $pageSize = \in_array($rawSize, $allowed, true) ? $rawSize : 20;
        $sortField = strtolower(trim((string) $request->query->get('users_sort', 'pseudo')));
        if ($sortField !== 'id') {
            $sortField = 'pseudo';
        }
        $sortDirection = strtolower(trim((string) $request->query->get('users_dir', 'asc')));
        if (!\in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'asc';
        }

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'sortField' => $sortField,
            'sortDirection' => $sortDirection,
        ];
    }

    /**
     * @brief Build one pane view model for a subject user (owned tree or flat + optional shared-as-grantee).
     * @param int $subjectUserId Subject user id (owner for owned section; grantee for shared section).
     * @param string $subjectUserLabel Stable label for headers.
     * @param Request $request HTTP request (folder, shared_folder).
     * @param SharedFileOwnerListCriteria $criteria Listing criteria.
     * @param bool $flatOwnedListing When true, list all owned files without folder navigation (admin all mode).
     * @param bool $showOwnedListingSection Whether owned section is active for criteria.
     * @param bool $showSharedListingSection Whether shared section is active (subject as grantee).
     * @param UserFilesCapabilities $capabilities Capability flags for UI/actions.
     * @param bool $useNamespacedFolderQueryKeys When true, read owned/shared folder cursors from `uf_{subject}` / `sf_{subject}` (multi-pane godview).
     * @return UserFilesPaneViewModel
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function buildPaneViewModel(
        int $subjectUserId,
        string $subjectUserLabel,
        Request $request,
        SharedFileOwnerListCriteria $criteria,
        bool $flatOwnedListing,
        bool $showOwnedListingSection,
        bool $showSharedListingSection,
        UserFilesCapabilities $capabilities,
        bool $useNamespacedFolderQueryKeys = false,
    ): UserFilesPaneViewModel {
        $paneId = 'user-files-pane-'.$subjectUserId;

        $files = [];
        $folders = [];
        $breadcrumbFolders = [];
        $currentFolder = null;
        $folderShareStates = [];
        $folderSizeBytes = [];
        $folderPublicLandingUrls = [];
        $currentFolderPublicLandingUrl = null;
        $currentFolderShareState = null;

        if ($showOwnedListingSection) {
            if ($flatOwnedListing) {
                $files = $this->sharedFileRepository->findOwnedFilteredAll($subjectUserId, $criteria);
            } else {
                $ownedFolderCursor = $useNamespacedFolderQueryKeys
                    ? (int) $request->query->get(UserPaneFolderQueryKeys::ownedFolderKey($subjectUserId), 0)
                    : (int) $request->query->get('folder', 0);
                $currentFolder = $this->folderTreeService->resolveCurrentFolder($subjectUserId, $ownedFolderCursor > 0 ? $ownedFolderCursor : null);
                $folders = $this->folderTreeService->listCurrentChildFolders($subjectUserId, $currentFolder);
                $breadcrumbFolders = $this->folderTreeService->buildBreadcrumb($currentFolder);
                $files = $this->sharedFileRepository->findOwnedFilteredInFolder($subjectUserId, $criteria, $currentFolder);
                foreach ($folders as $listedFolder) {
                    if ($listedFolder->getId() === null) {
                        continue;
                    }
                    $listedFolderId = (int) $listedFolder->getId();
                    $folderShareStates[$listedFolderId] = $this->folderPropertiesService->buildRecursiveShareState($subjectUserId, $listedFolder);
                    $folderSizeBytes[$listedFolderId] = $this->computeOwnedFolderSizeBytes($subjectUserId, $listedFolder);
                    $landingUrl = $this->resolvePublicLandingUrlForOwnerFolderSubtree($subjectUserId, $listedFolder);
                    if ($landingUrl !== null) {
                        $folderPublicLandingUrls[$listedFolderId] = $landingUrl;
                    }
                }
                $currentFolderPublicLandingUrl = $currentFolder !== null
                    ? $this->resolvePublicLandingUrlForOwnerFolderSubtree($subjectUserId, $currentFolder)
                    : null;
                if ($currentFolder !== null) {
                    $currentFolderShareState = $this->folderPropertiesService->buildRecursiveShareState($subjectUserId, $currentFolder);
                }
            }
        }

        $allSharedForMeFiles = [];
        if ($showSharedListingSection) {
            $allSharedForMeFiles = $this->sharedFileRepository->findSharedForGranteeAll($subjectUserId, $criteria);
        }

        $requestedSharedFolderId = $useNamespacedFolderQueryKeys
            ? (int) $request->query->get(UserPaneFolderQueryKeys::sharedFolderKey($subjectUserId), 0)
            : (int) $request->query->get('shared_folder', 0);
        if (!$showSharedListingSection) {
            $requestedSharedFolderId = 0;
        }
        $sharedListingContext = $this->sharedForMeTreeService->buildListingContext(
            $allSharedForMeFiles,
            $requestedSharedFolderId,
            $subjectUserId,
        );
        $sharedForMeCurrentFolderId = $sharedListingContext->currentFolderId;
        $sharedBreadcrumbFolders = $sharedListingContext->breadcrumbFolders;
        $sharedForMeFiles = $sharedListingContext->filesAtLevel;
        $sharedFolderSizeBytes = $sharedListingContext->folderSizeBytes;
        $sharedForMeFolders = [];
        foreach ($sharedListingContext->foldersAtLevel as $sharedForMeFolderRow) {
            $sharedFolderId = (int) ($sharedForMeFolderRow['id'] ?? 0);
            if ($sharedFolderId < 1) {
                continue;
            }
            $sharedForMeFolders[$sharedFolderId] = $sharedForMeFolderRow;
            if (!isset($sharedFolderSizeBytes[$sharedFolderId])) {
                $sharedFolderSizeBytes[$sharedFolderId] = 0;
            }
        }
        $sharedOwnerLabelsByFileId = [];
        $sharedOwnerLabelsByFolderId = [];
        $sharedOwnerIds = [];
        $sharedOwnerByFolderId = [];
        foreach ($sharedListingContext->registry as $sharedFolderId => $sharedFolderNode) {
            $sharedOwnerId = $sharedFolderNode->ownerUserId;
            if ($sharedOwnerId > 0) {
                $sharedOwnerIds[$sharedOwnerId] = $sharedOwnerId;
                $sharedOwnerByFolderId[$sharedFolderId] = $sharedOwnerId;
            }
        }
        foreach ($allSharedForMeFiles as $sharedForMeFile) {
            $sharedOwnerId = (int) $sharedForMeFile->getOwnerUserId();
            if ($sharedOwnerId > 0) {
                $sharedOwnerIds[$sharedOwnerId] = $sharedOwnerId;
            }
        }
        $sharedOwnerLabelsByUserId = [];
        foreach ($this->userRepository->findByIdsOrdered(array_values($sharedOwnerIds)) as $sharedOwnerUser) {
            $sharedOwnerLabel = trim((string) $sharedOwnerUser->getPseudonym());
            if ($sharedOwnerLabel === '') {
                $sharedOwnerLabel = trim((string) $sharedOwnerUser->getEmail());
            }
            $sharedOwnerLabelsByUserId[(int) $sharedOwnerUser->getId()] = $sharedOwnerLabel;
        }
        foreach ($sharedForMeFiles as $sharedForMeFile) {
            $sharedFileId = (int) ($sharedForMeFile->getId() ?? 0);
            if ($sharedFileId < 1) {
                continue;
            }
            $sharedOwnerId = (int) $sharedForMeFile->getOwnerUserId();
            $sharedOwnerLabelsByFileId[$sharedFileId] = $sharedOwnerLabelsByUserId[$sharedOwnerId] ?? (string) $sharedOwnerId;
        }
        foreach ($sharedForMeFolders as $sharedForMeFolder) {
            $sharedFolderId = (int) ($sharedForMeFolder['id'] ?? 0);
            if ($sharedFolderId < 1) {
                continue;
            }
            $sharedFolderOwnerId = (int) ($sharedOwnerByFolderId[$sharedFolderId] ?? 0);
            if ($sharedFolderOwnerId < 1) {
                continue;
            }
            $sharedOwnerLabelsByFolderId[$sharedFolderId] = $sharedOwnerLabelsByUserId[$sharedFolderOwnerId] ?? (string) $sharedFolderOwnerId;
        }
        if ($criteria->sortField === 'pseudo' && !$criteria->isSortNeutral()) {
            $sharedForMeFolders = $this->sortSharedFoldersByOwnerLabel($sharedForMeFolders, $sharedOwnerLabelsByFolderId, $criteria->sortDirection);
        }

        $grantMaps = [];
        $visibleFiles = [];
        foreach ($files as $row) {
            $visibleFiles[] = $row;
        }
        if ($showSharedListingSection) {
            foreach ($sharedForMeFiles as $row) {
                $visibleFiles[] = $row;
            }
        }
        foreach ($visibleFiles as $file) {
            if ($file->getId() === null) {
                continue;
            }
            $grantMaps[(int) $file->getId()] = $this->shareGrantRepository->findActiveGranteeIdsBySharedFile((int) $file->getId());
        }

        $isCurrentFolderCompletelyEmpty = $folders === [] && $files === [];

        $owned = [
            'files' => $files,
            'folders' => $folders,
            'folderShareStates' => $folderShareStates,
            'folderSizeBytes' => $folderSizeBytes,
            'folderPublicLandingUrls' => $folderPublicLandingUrls,
            'currentFolderPublicLandingUrl' => $currentFolderPublicLandingUrl,
            'currentFolderShareState' => $currentFolderShareState,
            'currentFolder' => $currentFolder,
            'breadcrumbFolders' => $breadcrumbFolders,
            'isCurrentFolderCompletelyEmpty' => $isCurrentFolderCompletelyEmpty,
        ];

        $shared = [
            'sharedForMeFiles' => $sharedForMeFiles,
            'sharedForMeFolders' => array_values($sharedForMeFolders),
            'sharedBreadcrumbFolders' => $sharedBreadcrumbFolders,
            'sharedFolderSizeBytes' => $sharedFolderSizeBytes,
            'sharedOwnerLabelsByFileId' => $sharedOwnerLabelsByFileId,
            'sharedOwnerLabelsByFolderId' => $sharedOwnerLabelsByFolderId,
            'sharedForMeCurrentFolderId' => $sharedForMeCurrentFolderId,
            'hasSharedForMe' => $allSharedForMeFiles !== [] || $sharedListingContext->registry !== [],
        ];

        return new UserFilesPaneViewModel(
            $paneId,
            $subjectUserId,
            $subjectUserLabel,
            $capabilities,
            $owned,
            $shared,
            $grantMaps,
            $showOwnedListingSection,
            $showSharedListingSection,
            \count($files),
        );
    }

    /**
     * @brief Sort shared folder rows by owner label and deterministic fallbacks.
     * @param array<int, array{id:int,name:string}> $sharedForMeFolders Shared folders keyed by id.
     * @param array<int, string> $ownerLabelsByFolderId Owner labels by folder id.
     * @param string $sortDirection Requested sort direction.
     * @return array<int, array{id:int,name:string}>
     * @date 2026-05-07
     * @author Stephane H.
     */
    private function sortSharedFoldersByOwnerLabel(
        array $sharedForMeFolders,
        array $ownerLabelsByFolderId,
        string $sortDirection
    ): array {
        $directionMultiplier = strtoupper($sortDirection) === 'ASC' ? 1 : -1;
        uasort(
            $sharedForMeFolders,
            static function (array $leftFolder, array $rightFolder) use ($ownerLabelsByFolderId, $directionMultiplier): int {
                $leftId = (int) ($leftFolder['id'] ?? 0);
                $rightId = (int) ($rightFolder['id'] ?? 0);
                $leftLabel = mb_strtolower(trim((string) ($ownerLabelsByFolderId[$leftId] ?? '')), 'UTF-8');
                $rightLabel = mb_strtolower(trim((string) ($ownerLabelsByFolderId[$rightId] ?? '')), 'UTF-8');
                $labelComparison = $leftLabel <=> $rightLabel;
                if ($labelComparison !== 0) {
                    return $labelComparison * $directionMultiplier;
                }
                if ($leftId !== $rightId) {
                    return ($leftId <=> $rightId) * $directionMultiplier;
                }

                return strcmp((string) ($leftFolder['name'] ?? ''), (string) ($rightFolder['name'] ?? '')) * $directionMultiplier;
            }
        );

        return $sharedForMeFolders;
    }

    /**
     * @brief Build admin multi-user page model (paginated ROLE_SHARE users, one pane each including empty users).
     * @param Request $request HTTP request.
     * @param User $viewer Authenticated viewer (admin).
     * @param SharedFileOwnerListCriteria $criteria Listing criteria.
     * @param bool $showOwnedListingSection Owned section flag from criteria.
     * @param bool $showSharedListingSection Shared section flag from criteria.
     * @param UserFilesCapabilities $capabilities Capabilities applied to each pane.
     * @return FilesPageViewModel
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function buildAdminAllUsersPage(
        Request $request,
        User $viewer,
        SharedFileOwnerListCriteria $criteria,
        bool $showOwnedListingSection,
        bool $showSharedListingSection,
        UserFilesCapabilities $capabilities,
    ): FilesPageViewModel {
        $viewerId = (int) $viewer->getId();
        $params = $this->parseUsersPaginationParams($request);
        $totalUsers = $this->userRepository->countActiveUsersByRoleTokenLike($viewerId, 'ROLE_SHARE');
        $totalPages = max(1, (int) ceil($totalUsers / $params['pageSize']));
        $page = min($params['page'], $totalPages);
        $offset = ($page - 1) * $params['pageSize'];

        $pageUsers = $this->userRepository->findActiveUsersByRoleTokenLikePaginated(
            $viewerId,
            'ROLE_SHARE',
            $params['sortField'],
            $params['sortDirection'],
            $params['pageSize'],
            $offset
        );

        $panes = [];
        foreach ($pageUsers as $shareUser) {
            $sid = (int) ($shareUser->getId() ?? 0);
            if ($sid < 1) {
                continue;
            }
            $pseudo = trim((string) $shareUser->getPseudonym());
            $email = trim((string) $shareUser->getEmail());
            $label = $pseudo !== '' ? $pseudo : $email;
            $panes[] = $this->buildPaneViewModel(
                $sid,
                $label,
                $request,
                $criteria,
                false,
                $showOwnedListingSection,
                $showSharedListingSection,
                $capabilities,
                true,
            );
        }

        $paneParam = trim((string) $request->query->get('pane', ''));
        $focusPaneId = $paneParam !== '' ? $paneParam : null;

        $pagination = new UsersPanePagination(
            $page,
            $params['pageSize'],
            $totalUsers,
            $totalPages,
            $params['sortField'],
            $params['sortDirection'],
        );

        return new FilesPageViewModel(
            'all',
            $criteria->listingScope,
            $panes,
            $focusPaneId,
            $pagination,
            $params['sortField'],
            $params['sortDirection'],
        );
    }

    /**
     * @brief Resolve public landing URL for one owned folder subtree root.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Folder entity.
     * @return string|null Absolute URL or null.
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function resolvePublicLandingUrlForOwnerFolderSubtree(int $ownerUserId, Folder $folder): ?string
    {
        if ((int) $folder->getOwnerUserId() !== $ownerUserId) {
            return null;
        }
        if (!$folder->isPublicShareEffectivelyActive()) {
            return null;
        }

        $this->folderPublicTokenService->ensurePublicFolderToken($folder);
        $token = $folder->getPublicFolderToken();
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $this->urlGenerator->generate('folder_public_landing', ['publicToken' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @brief Compute recursive byte size for one owned folder.
     * @param int $ownerUserId Owner user id.
     * @param Folder $folder Root folder.
     * @return int Total bytes.
     * @date 2026-05-04
     * @author Stephane H.
     */
    private function computeOwnedFolderSizeBytes(int $ownerUserId, Folder $folder): int
    {
        $totalBytes = 0;
        $subtreeFolders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        foreach ($subtreeFolders as $subFolder) {
            $rows = $this->sharedFileRepository->findBy([
                'ownerUserId' => $ownerUserId,
                'folder' => $subFolder,
            ]);
            foreach ($rows as $row) {
                if ($row instanceof SharedFile) {
                    $totalBytes += (int) $row->getByteSize();
                }
            }
        }

        return $totalBytes;
    }
}
