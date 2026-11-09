<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\DownloadArchive;
use App\Repository\DownloadArchiveRepository;
use App\Service\Download\DownloadArchiveService;
use App\Service\Download\DownloadGrantService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @brief Cancel and remove all non-done Famille B operations for one owner/actor.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class OperationsPurgeService
{
    /**
     * @brief Construct the operations purge aggregator.
     * @param UploadEncryptJobService $uploadEncryptJobService Encrypt jobs.
     * @param ZipExtractService $zipExtractService Extract jobs.
     * @param ChunkedUploadService $chunkedUploadService Orphan chunk sessions.
     * @param DownloadArchiveRepository $downloadArchiveRepository Archive rows.
     * @param DownloadArchiveService $downloadArchiveService Archive cancel.
     * @param OperationsListService $operationsListService Post-purge listing.
     * @param LoggerInterface $uploadLogger Upload diagnostic channel.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function __construct(
        private readonly UploadEncryptJobService $uploadEncryptJobService,
        private readonly ZipExtractService $zipExtractService,
        private readonly ChunkedUploadService $chunkedUploadService,
        private readonly DownloadArchiveRepository $downloadArchiveRepository,
        private readonly DownloadArchiveService $downloadArchiveService,
        private readonly OperationsListService $operationsListService,
        #[Autowire(service: 'monolog.logger.upload')]
        private readonly LoggerInterface $uploadLogger,
    ) {
    }

    /**
     * @brief Purge non-done operations and return the refreshed OPS list.
     * @param int $ownerUserId Effective storage owner.
     * @param int $actorUserId Authenticated actor (archives are actor-scoped).
     * @return array{cancelled: int, removed: int, tasks: list<array<string, mixed>>}
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function purgeNonDone(int $ownerUserId, int $actorUserId): array
    {
        $encrypt = $this->uploadEncryptJobService->purgeNonDoneForOwner($ownerUserId);
        $extract = $this->zipExtractService->purgeNonDoneForOwner($ownerUserId);
        $chunks = $this->chunkedUploadService->abortAllSessionsForOwner($ownerUserId);
        $archives = $this->purgeArchivesForActor($actorUserId);

        $cancelled = $encrypt['cancelled'] + $extract['cancelled'] + $archives['cancelled'];
        $removed = $encrypt['removed'] + $extract['removed'] + $archives['removed'] + $chunks;

        $this->uploadLogger->info('upload.ops.purge', [
            'owner_user_id' => $ownerUserId,
            'actor_user_id' => $actorUserId,
            'cancelled' => $cancelled,
            'removed' => $removed,
            'encrypt_cancelled' => $encrypt['cancelled'],
            'encrypt_removed' => $encrypt['removed'],
            'extract_cancelled' => $extract['cancelled'],
            'extract_removed' => $extract['removed'],
            'archive_cancelled' => $archives['cancelled'],
            'archive_removed' => $archives['removed'],
            'chunk_sessions_aborted' => $chunks,
        ]);

        return [
            'cancelled' => $cancelled,
            'removed' => $removed,
            'tasks' => $this->operationsListService->listForOwner($ownerUserId, $actorUserId),
        ];
    }

    /**
     * @brief Cancel preparing archives and expire failed ones for the actor.
     * @param int $actorUserId Actor user id.
     * @return array{cancelled: int, removed: int}
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function purgeArchivesForActor(int $actorUserId): array
    {
        $cancelled = 0;
        $removed = 0;
        $archives = $this->downloadArchiveRepository->findRecentForActor(
            DownloadGrantService::ACTOR_USER,
            (string) $actorUserId,
            100,
        );
        foreach ($archives as $archive) {
            $status = $archive->getStatus();
            if ($status === DownloadArchive::STATUS_READY) {
                continue;
            }
            if ($status === DownloadArchive::STATUS_PREPARING) {
                $this->downloadArchiveService->cancelPrepare($archive);
                ++$cancelled;
            }
            $archive->setStatus(DownloadArchive::STATUS_EXPIRED);
            $this->downloadArchiveRepository->save($archive);
            ++$removed;
        }

        return ['cancelled' => $cancelled, 'removed' => $removed];
    }
}
