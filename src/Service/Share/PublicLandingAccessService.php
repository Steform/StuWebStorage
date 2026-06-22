<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Entity\SharedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Centralizes anonymous public landing/download access rules (single source for 404 vs allowed).
 */
final class PublicLandingAccessService
{
    /**
     * @brief Require a shared file resolved by public token to allow anonymous public channel access (landing, preview, download).
     * @param SharedFile|null $sharedFile Resolved aggregate or null when token is unknown.
     * @return SharedFile Non-null aggregate passing isPublicShareActive().
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function requireAccessiblePublicSharedFile(?SharedFile $sharedFile): SharedFile
    {
        if (!$sharedFile instanceof SharedFile || !$sharedFile->isPublicShareActive()) {
            throw new NotFoundHttpException();
        }

        return $sharedFile;
    }

    /**
     * @brief Require a folder resolved by public_folder_token to allow anonymous folder landing / ZIP flow.
     * @param Folder|null $folder Resolved aggregate or null when token is unknown.
     * @return Folder Non-null aggregate passing isPublicShareEffectivelyActive().
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function requireAccessiblePublicFolder(?Folder $folder): Folder
    {
        if (!$folder instanceof Folder || !$folder->isPublicShareEffectivelyActive()) {
            throw new NotFoundHttpException();
        }

        return $folder;
    }
}
