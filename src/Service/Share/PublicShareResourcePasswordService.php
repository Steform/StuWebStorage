<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Folder;
use App\Entity\SharedFile;

/**
 * @brief Generates, stores, or clears optional public-share password credentials on SharedFile and Folder.
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicShareResourcePasswordService
{
    public function __construct(
        private readonly PublicSharePasswordGenerator $generator,
        private readonly PublicSharePasswordCredentialService $credentialService,
        private readonly PublicSharePasswordVault $vault,
    ) {
    }

    /**
     * @brief Apply password intent for an enabled public file share; returns plain once when generated.
     * @param SharedFile $sharedFile Target file aggregate.
     * @param bool $wantPassword When true, generate new credentials; when false, clear stored credentials.
     * @return string|null Generated plain password for owner response, or null.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function applyToSharedFile(SharedFile $sharedFile, bool $wantPassword): ?string
    {
        if (!$wantPassword) {
            $this->clearSharedFile($sharedFile);

            return null;
        }

        $plain = $this->generator->generate();
        $hash = $this->credentialService->hashPlainPassword($plain);
        $enc = $this->vault->encrypt($plain);
        $sharedFile->setPublicPasswordEnabled(true);
        $sharedFile->setPublicPasswordHash($hash);
        $sharedFile->setPublicPasswordSecret($enc);

        return $plain;
    }

    /**
     * @brief Remove password credentials from a shared file row.
     * @param SharedFile $sharedFile Target aggregate.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function clearSharedFile(SharedFile $sharedFile): void
    {
        $sharedFile->setPublicPasswordEnabled(false);
        $sharedFile->clearPublicPasswordCredentials();
    }

    /**
     * @brief Apply password intent for an enabled public folder share.
     * @param Folder $folder Target folder.
     * @param bool $wantPassword Generate or clear.
     * @return string|null Plain password when generated.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function applyToFolder(Folder $folder, bool $wantPassword): ?string
    {
        if (!$wantPassword) {
            $this->clearFolder($folder);

            return null;
        }

        $plain = $this->generator->generate();
        $hash = $this->credentialService->hashPlainPassword($plain);
        $enc = $this->vault->encrypt($plain);
        $folder->setPublicPasswordEnabled(true);
        $folder->setPublicPasswordHash($hash);
        $folder->setPublicPasswordSecret($enc);

        return $plain;
    }

    /**
     * @brief Clear folder password credentials.
     * @param Folder $folder Target folder.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function clearFolder(Folder $folder): void
    {
        $folder->setPublicPasswordEnabled(false);
        $folder->clearPublicPasswordCredentials();
    }

    /**
     * @brief Decrypt owner-visible password for API (authenticated routes only).
     * @param SharedFile $sharedFile File aggregate.
     * @return string|null Plain password or null.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function decryptPlainForOwnerSharedFile(SharedFile $sharedFile): ?string
    {
        if (!$sharedFile->isPublicPasswordEnabled()) {
            return null;
        }

        return $this->vault->decrypt($sharedFile->getPublicPasswordSecret());
    }

    /**
     * @brief Decrypt owner-visible password for folder (authenticated routes only).
     * @param Folder $folder Folder aggregate.
     * @return string|null Plain password or null.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function decryptPlainForOwnerFolder(Folder $folder): ?string
    {
        if (!$folder->isPublicPasswordEnabled()) {
            return null;
        }

        return $this->vault->decrypt($folder->getPublicPasswordSecret());
    }
}
