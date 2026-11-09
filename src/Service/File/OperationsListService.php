<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\DownloadArchive;
use App\Repository\DownloadArchiveRepository;
use App\Service\Download\DownloadArchiveService;
use App\Service\Download\DownloadGrantService;

/**
 * @brief Aggregate Famille B operations (encrypt, extract, archive) into the OPS task contract.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class OperationsListService
{
    /**
     * @brief Construct the operations aggregator.
     * @param UploadEncryptJobService $uploadEncryptJobService Encrypt jobs.
     * @param ZipExtractService $zipExtractService Extract jobs.
     * @param DownloadArchiveRepository $downloadArchiveRepository Archive rows.
     * @param DownloadArchiveService $downloadArchiveService Archive public mapping.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function __construct(
        private readonly UploadEncryptJobService $uploadEncryptJobService,
        private readonly ZipExtractService $zipExtractService,
        private readonly DownloadArchiveRepository $downloadArchiveRepository,
        private readonly DownloadArchiveService $downloadArchiveService,
    ) {
    }

    /**
     * @brief List operations for one storage owner and the current actor.
     * @param int $ownerUserId Effective storage owner (godview-aware).
     * @param int $actorUserId Authenticated actor id (archives are actor-scoped).
     * @return list<array<string, mixed>> OPS task payloads, newest first.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function listForOwner(int $ownerUserId, int $actorUserId): array
    {
        $tasks = [];
        foreach ($this->uploadEncryptJobService->listForOwner($ownerUserId) as $row) {
            $tasks[] = $this->normalizeEncrypt($row);
        }
        foreach ($this->zipExtractService->listForOwner($ownerUserId) as $row) {
            $tasks[] = $row;
        }
        $archives = $this->downloadArchiveRepository->findRecentForActor(
            DownloadGrantService::ACTOR_USER,
            (string) $actorUserId,
            20,
        );
        foreach ($archives as $archive) {
            $mapped = $this->fromArchive($archive);
            if ($mapped !== null) {
                $tasks[] = $mapped;
            }
        }
        usort(
            $tasks,
            static fn (array $a, array $b): int => ((int) ($b['updatedAt'] ?? 0)) <=> ((int) ($a['updatedAt'] ?? 0)),
        );

        return $tasks;
    }

    /**
     * @brief Fill OPS contract fields on an encrypt job payload.
     * @param array<string, mixed> $row Encrypt public array.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function normalizeEncrypt(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $row['phaseKey'] = $row['phaseKey'] ?? 'files.ops.phase.encrypt';
        $row['progressKnown'] = true;
        $row['cancelSupported'] = $status === UploadEncryptJobService::STATUS_RUNNING;
        $row['payload'] = [
            'sharedFileId' => $row['sharedFileId'] ?? null,
        ];

        return $row;
    }

    /**
     * @brief Map a download archive row to an OPS task, or null when expired.
     * @param DownloadArchive $archive Archive entity.
     * @return array<string, mixed>|null
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function fromArchive(DownloadArchive $archive): ?array
    {
        $rawStatus = $archive->getStatus();
        if ($rawStatus === DownloadArchive::STATUS_EXPIRED) {
            return null;
        }
        $status = match ($rawStatus) {
            DownloadArchive::STATUS_PREPARING => 'running',
            DownloadArchive::STATUS_READY => 'done',
            DownloadArchive::STATUS_FAILED => 'failed',
            default => 'failed',
        };
        $total = $archive->getTotalBytes();
        $done = $archive->getPreparedBytes();
        $percent = $total > 0 ? (int) min(100, floor(($done / $total) * 100)) : 0;
        $public = $this->downloadArchiveService->toPublicArray($archive);

        return [
            'taskId' => $archive->getToken(),
            'type' => 'archive_prepare',
            'status' => $status,
            'label' => $archive->getFileName(),
            'phaseKey' => $status === 'running' ? 'files.ops.phase.archive' : null,
            'doneBytes' => $done,
            'totalBytes' => $total,
            'percent' => $percent,
            'progressKnown' => true,
            'cancelSupported' => $status === 'running',
            'modalKey' => 'files_archive',
            'errorKey' => $archive->getErrorCode(),
            'notified' => $status !== 'running',
            'createdAt' => $archive->getCreatedAt()->getTimestamp(),
            'updatedAt' => $archive->getCreatedAt()->getTimestamp(),
            'finishedAt' => $status === 'running' ? null : $archive->getExpiresAt()->getTimestamp(),
            'payload' => [
                'archiveToken' => $archive->getToken(),
                'archiveStatus' => $rawStatus,
                'fileName' => $public['fileName'],
            ],
        ];
    }
}
