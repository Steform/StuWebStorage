<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * @brief Per-subject query parameter names for folder navigation in multi-user file panes (admin godview).
 * @details Global `folder` and `shared_folder` apply to a single subject; with multiple panes each subject uses `uf_{id}` and `sf_{id}`.
 * @date 2026-05-04
 * @author Stephane H.
 */
final class UserPaneFolderQueryKeys
{
    /**
     * @brief HTTP query key for the subject's owned-folder cursor.
     * @param int $subjectUserId Subject user id.
     * @return string
     * @date 2026-05-04
     * @author Stephane H.
     */
    public static function ownedFolderKey(int $subjectUserId): string
    {
        return 'uf_'.$subjectUserId;
    }

    /**
     * @brief HTTP query key for the subject's shared-folder cursor (grantee tree).
     * @param int $subjectUserId Subject user id.
     * @return string
     * @date 2026-05-04
     * @author Stephane H.
     */
    public static function sharedFolderKey(int $subjectUserId): string
    {
        return 'sf_'.$subjectUserId;
    }
}
