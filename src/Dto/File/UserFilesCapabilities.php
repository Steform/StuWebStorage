<?php

declare(strict_types=1);

namespace App\Dto\File;

/**
 * @brief UI/action capability flags for one files pane (derived from viewer roles and pane subject).
 * @date 2026-05-04
 * @author Stephane H.
 */
final readonly class UserFilesCapabilities
{
    /**
     * @brief Construct capability flags for a pane.
     * @param bool $canManageOwned Whether owned files/folders actions are allowed for this pane.
     * @param bool $canManageShared Whether incoming-share actions are allowed for this pane.
     * @param bool $canUpload Whether upload targets this pane subject.
     * @param bool $canBulkActions Whether bulk toolbar actions apply to this pane.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function __construct(
        public bool $canManageOwned = true,
        public bool $canManageShared = true,
        public bool $canUpload = true,
        public bool $canBulkActions = true,
    ) {
    }
}
