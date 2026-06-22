<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Repository\FolderRepository;
use App\Repository\PublicDownloadChallengeRepository;
use App\Repository\SharedFileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service FolderPublicTokenService.
 */
final class FolderPublicTokenService
{
    private const MATERIALIZE_ATTEMPTS = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderRepository $folderRepository,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly PublicDownloadChallengeRepository $publicDownloadChallengeRepository,
    ) {
    }

    /**
     * @brief Ensure the folder has a persistent public_folder_token when folder public sharing is active.
     * @param Folder $folder Managed folder aggregate.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function ensurePublicFolderToken(Folder $folder): void
    {
        $existing = $folder->getPublicFolderToken();
        if (is_string($existing) && $existing !== '') {
            return;
        }

        for ($i = 0; $i < self::MATERIALIZE_ATTEMPTS; ++$i) {
            $token = bin2hex(random_bytes(16));
            if ($this->folderRepository->findOneByPublicFolderToken($token) !== null) {
                continue;
            }
            if ($this->sharedFileRepository->findOneByPublicToken($token) !== null) {
                continue;
            }
            $folder->setPublicFolderToken($token);
            $this->entityManager->flush();

            return;
        }

        throw new \RuntimeException('folder.public_token.materialize_failed');
    }

    /**
     * @brief Revoke folder landing token and purge related public-download challenges.
     * @param Folder $folder Managed folder aggregate.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function revokePublicFolderToken(Folder $folder): void
    {
        $old = $folder->getPublicFolderToken();
        if (is_string($old) && $old !== '') {
            $this->publicDownloadChallengeRepository->deleteByPublicToken($old);
        }
        $folder->setPublicFolderToken(null);
        $this->entityManager->flush();
    }
}
