<?php

declare(strict_types=1);

namespace App\Service\Download;

use App\Entity\DownloadArchive;
use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;

/**
 * @brief Range-capable resource that synthesizes a Zip64 STORE archive on the fly.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class ArchiveDownloadResource implements DownloadResourceInterface
{
    private VirtualZipLayout $layout;

    /** @var list<SharedFile> */
    private array $filesByIndex = [];

    /**
     * @brief Construct archive resource from a ready DownloadArchive and resolved files.
     * @param DownloadArchive $archive Archive entity (status ready).
     * @param VirtualZipLayout $layout Precomputed layout.
     * @param list<SharedFile> $files Files ordered like layout entries.
     * @param EncryptedRangeReader $rangeReader Encrypted range reader.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly DownloadArchive $archive,
        VirtualZipLayout $layout,
        array $files,
        private readonly EncryptedRangeReader $rangeReader,
    ) {
        $this->layout = $layout;
        $this->filesByIndex = array_values($files);
    }

    /**
     * @brief Factory from archive entity + repository + reader (validates entries).
     * @param DownloadArchive $archive Ready archive.
     * @param SharedFileRepository $sharedFileRepository File repository.
     * @param EncryptedRangeReader $rangeReader Range reader.
     * @return self
     * @throws \RuntimeException When an entry is invalid (caller should map to 410).
     * @date 2026-11-02
     * @author Stephane H.
     */
    public static function fromReadyArchive(
        DownloadArchive $archive,
        SharedFileRepository $sharedFileRepository,
        EncryptedRangeReader $rangeReader,
    ): self {
        $entries = $archive->getEntriesJson();
        $layoutEntries = [];
        $files = [];
        foreach ($entries as $entry) {
            $fileId = (int) ($entry['fileId'] ?? 0);
            $sharedFile = $sharedFileRepository->find($fileId);
            if (!$sharedFile instanceof SharedFile) {
                throw new \RuntimeException('download.archive_entry_missing');
            }
            $size = (int) ($entry['size'] ?? 0);
            $crc32 = $entry['crc32'] ?? null;
            $mtime = (int) ($entry['mtime'] ?? 0);
            if ($sharedFile->getByteSize() !== $size) {
                throw new \RuntimeException('download.archive_entry_changed');
            }
            if ($crc32 === null || $sharedFile->getContentCrc32() !== (int) $crc32) {
                throw new \RuntimeException('download.archive_entry_changed');
            }
            if ($sharedFile->getUpdatedAt()->getTimestamp() !== $mtime) {
                throw new \RuntimeException('download.archive_entry_changed');
            }
            $layoutEntries[] = [
                'name' => (string) ($entry['entryName'] ?? $sharedFile->getOriginalFileName()),
                'size' => $size,
                'mtime' => $mtime,
                'crc32' => (int) $crc32,
            ];
            $files[] = $sharedFile;
        }

        $layout = VirtualZipLayout::fromEntries($layoutEntries);
        if ($layout->getTotalSize() !== $archive->getTotalBytes()) {
            throw new \RuntimeException('download.archive_layout_mismatch');
        }

        return new self($archive, $layout, $files, $rangeReader);
    }

    public function getSize(): int
    {
        return $this->layout->getTotalSize();
    }

    public function getStrongEtag(): string
    {
        $etag = $this->archive->getEtag();
        if (is_string($etag) && $etag !== '') {
            return $etag;
        }

        return '"a-'.$this->archive->getToken().'-'.$this->getSize().'"';
    }

    public function getLastModified(): \DateTimeImmutable
    {
        return $this->archive->getReadyAt() ?? $this->archive->getCreatedAt();
    }

    public function getFileName(): string
    {
        return $this->archive->getFileName();
    }

    public function getMimeType(): string
    {
        return 'application/zip';
    }

    public function supportsRanges(): bool
    {
        return true;
    }

    public function readRange(int $start, int $length, callable $sink): int
    {
        $regions = $this->layout->resolveRegions($start, $length);
        $written = 0;
        $trailerBlob = null;
        $trailerBase = $this->layout->getCentralDirectoryOffset();

        foreach ($regions as $region) {
            $type = $region['type'];
            $archiveStart = $region['archiveStart'];
            $archiveEnd = $region['archiveEnd'];
            $need = $archiveEnd - $archiveStart;
            if ($need < 1) {
                continue;
            }

            if ($type === 'data') {
                $entryIndex = (int) ($region['entryIndex'] ?? -1);
                $file = $this->filesByIndex[$entryIndex] ?? null;
                if (!$file instanceof SharedFile) {
                    throw new \RuntimeException('download.archive_entry_missing');
                }
                $sourceOffset = (int) $region['sourceOffset'];
                $path = $file->getStoragePath();
                $this->rangeReader->ensureIndex($path, $file->getByteSize());
                $written += $this->rangeReader->readRange($path, $sourceOffset, $need, $sink);
                continue;
            }

            if ($type === 'local_header') {
                $entryIndex = (int) ($region['entryIndex'] ?? -1);
                $blob = $this->layout->buildLocalHeader($entryIndex);
                $entries = $this->layout->getEntries();
                $regionBase = $entries[$entryIndex]['localOffset'];
                $slice = $this->layout->sliceBlob($blob, $regionBase, $archiveStart, $archiveEnd);
                $sink($slice);
                $written += strlen($slice);
                continue;
            }

            if ($trailerBlob === null) {
                $trailerBlob = $this->layout->buildCentralDirectoryAndEndings();
            }
            $slice = $this->layout->sliceBlob($trailerBlob, $trailerBase, $archiveStart, $archiveEnd);
            $sink($slice);
            $written += strlen($slice);
        }

        return $written;
    }
}
