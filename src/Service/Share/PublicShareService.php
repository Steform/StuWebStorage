<?php

namespace App\Service\Share;

use App\Entity\SharedFile;
use App\Repository\PublicDownloadChallengeRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service PublicShareService.
 *
 * Sprint 22 (2026-04-28): owns every state transition of the public sharing channel
 * (enable, disable, expiration update). Disabling rotates the public token and purges
 * any pending TOTP challenge tied to the previous token. Friends grants are NEVER
 * touched here; they live in FriendsShareService.
 */
class PublicShareService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicDownloadChallengeRepository $publicDownloadChallengeRepository,
        private readonly PublicShareResourcePasswordService $publicShareResourcePasswordService,
    ) {
    }

    /**
     * @brief Enable the public channel and set its expiration instant (null = unlimited).
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @param DateTimeImmutable|null $publicExpiresAt Optional public-channel expiration instant.
     * @return array{previous: array{is_public: bool, public_expires_at: ?string, public_token: string}, current: array{is_public: bool, public_expires_at: ?string, public_token: string}}
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function enablePublic(SharedFile $sharedFile, ?DateTimeImmutable $publicExpiresAt): array
    {
        $previous = $this->snapshotPublicState($sharedFile);

        $sharedFile->setIsPublic(true);
        $sharedFile->setPublicExpiresAt($publicExpiresAt);
        $sharedFile->setExpiresAt($publicExpiresAt);
        $sharedFile->touchUpdatedAt();
        $this->entityManager->flush();

        return ['previous' => $previous, 'current' => $this->snapshotPublicState($sharedFile)];
    }

    /**
     * @brief Disable the public channel: rotate the public token, clear expiration and purge challenges.
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @return array{previous: array{is_public: bool, public_expires_at: ?string, public_token: string}, current: array{is_public: bool, public_expires_at: ?string, public_token: string}}
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function disablePublic(SharedFile $sharedFile): array
    {
        $previous = $this->snapshotPublicState($sharedFile);

        if ($sharedFile->isPublic()) {
            $previousToken = $sharedFile->getPublicToken();
            $this->publicDownloadChallengeRepository->deleteByPublicToken($previousToken);
            $sharedFile->setPublicToken(bin2hex(random_bytes(16)));
        }

        $this->publicShareResourcePasswordService->clearSharedFile($sharedFile);

        $sharedFile->setIsPublic(false);
        $sharedFile->setPublicExpiresAt(null);
        $sharedFile->setExpiresAt(null);
        $sharedFile->touchUpdatedAt();
        $this->entityManager->flush();

        return ['previous' => $previous, 'current' => $this->snapshotPublicState($sharedFile)];
    }

    /**
     * @brief Update the public-channel expiration instant without touching the activation flag.
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @param DateTimeImmutable|null $publicExpiresAt New expiration instant or null for unlimited.
     * @return array{previous: array{is_public: bool, public_expires_at: ?string, public_token: string}, current: array{is_public: bool, public_expires_at: ?string, public_token: string}}
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function updatePublicExpiration(SharedFile $sharedFile, ?DateTimeImmutable $publicExpiresAt): array
    {
        $previous = $this->snapshotPublicState($sharedFile);

        $sharedFile->setPublicExpiresAt($publicExpiresAt);
        if ($sharedFile->isPublic()) {
            $sharedFile->setExpiresAt($publicExpiresAt);
        }
        $sharedFile->touchUpdatedAt();
        $this->entityManager->flush();

        return ['previous' => $previous, 'current' => $this->snapshotPublicState($sharedFile)];
    }

    /**
     * @brief Build a serializable snapshot of the public channel state for audit/rollback.
     * @param SharedFile $sharedFile Target shared file aggregate.
     * @return array{is_public: bool, public_expires_at: ?string, public_token: string}
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function snapshotPublicState(SharedFile $sharedFile): array
    {
        return [
            'is_public' => $sharedFile->isPublic(),
            'public_expires_at' => $sharedFile->getPublicExpiresAt()?->format(DATE_ATOM),
            'public_token' => $sharedFile->getPublicToken(),
        ];
    }
}
