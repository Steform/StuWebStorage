<?php

declare(strict_types=1);

namespace App\Dto\File;

/**
 * @brief One user pane: owned + shared sections and metadata for Twig/JS.
 * @details Used when admin godview lists all users (`useUserFilesPanes`). Owned/shared folder cursors use query keys `uf_{subjectUserId}` and `sf_{subjectUserId}` (see UserPaneFolderQueryKeys). The partial `templates/files/components/_user_files_pane.html.twig` expects `owned.files`, `owned.folders`, grant maps, and shared aggregates built by UserFilesPaneBuilderService.
 * @date 2026-05-04
 * @author Stephane H.
 */
final readonly class UserFilesPaneViewModel
{
    /**
     * @brief Construct a pane view model.
     * @param string $paneId Stable DOM id (e.g. user-files-pane-123).
     * @param int $subjectUserId Subject user id for this pane.
     * @param string $subjectUserLabel Display label (pseudonym or email).
     * @param UserFilesCapabilities $capabilities Action capabilities for this pane.
     * @param array<string, mixed> $owned Keys mirror legacy listing vars: files, folders, folderShareStates, folderSizeBytes, folderPublicLandingUrls, currentFolder, breadcrumbFolders, currentFolderPublicLandingUrl, currentFolderShareState, isCurrentFolderCompletelyEmpty.
     * @param array<string, mixed> $shared Keys: sharedForMeFiles, sharedForMeFolders, sharedFolderSizeBytes, sharedForMeCurrentFolderId, hasSharedForMe.
     * @param array<int, array<int>> $grantMaps Grantee ids per file id for this pane.
     * @param bool $showOwnedSection Whether owned accordion is shown.
     * @param bool $showSharedSection Whether shared accordion is shown (for subject as grantee).
     * @param int $ownedTotal Owned file count in current folder view.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function __construct(
        public string $paneId,
        public int $subjectUserId,
        public string $subjectUserLabel,
        public UserFilesCapabilities $capabilities,
        public array $owned,
        public array $shared,
        public array $grantMaps,
        public bool $showOwnedSection,
        public bool $showSharedSection,
        public int $ownedTotal = 0,
    ) {
    }
}
