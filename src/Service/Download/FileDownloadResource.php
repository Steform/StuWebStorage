<?php

declare(strict_types=1);

namespace App\Service\Download;

use App\Entity\SharedFile;

/**
 * @brief Downloadable resource backed by one SharedFile (encrypted at rest).
 * @date 2026-11-02
 * @author Stephane H.
 */
final class FileDownloadResource implements DownloadResourceInterface
{
    /**
     * @brief Construct file download resource.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @param EncryptedRangeReader $rangeReader Encrypted range reader.
     * @param string $mimeType Content-Type value.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly SharedFile $sharedFile,
        private readonly EncryptedRangeReader $rangeReader,
        private readonly string $mimeType = 'application/octet-stream',
    ) {
    }

    /**
     * @brief Shared file identifier.
     * @return int
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function getSharedFileId(): int
    {
        return (int) ($this->sharedFile->getId() ?? 0);
    }

    public function getSize(): int
    {
        return $this->sharedFile->getByteSize();
    }

    public function getStrongEtag(): string
    {
        $id = $this->getSharedFileId();
        $sha = $this->sharedFile->getContentSha256();
        if (is_string($sha) && $sha !== '') {
            return '"f-'.$id.'-'.substr($sha, 0, 32).'"';
        }

        return '"f-'.$id.'-'.$this->sharedFile->getByteSize().'-'.$this->sharedFile->getUpdatedAt()->getTimestamp().'"';
    }

    public function getLastModified(): \DateTimeImmutable
    {
        return $this->sharedFile->getUpdatedAt();
    }

    public function getFileName(): string
    {
        $name = $this->sharedFile->getOriginalFileName();

        return $name !== '' ? $name : 'download.bin';
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function supportsRanges(): bool
    {
        return $this->rangeReader->supportsRanges($this->sharedFile->getStoragePath());
    }

    public function readRange(int $start, int $length, callable $sink): int
    {
        $path = $this->sharedFile->getStoragePath();
        if ($path === '' || !is_readable($path)) {
            throw new \RuntimeException('download.storage_unreadable');
        }

        if (!$this->supportsRanges()) {
            if ($start !== 0) {
                throw new \RuntimeException('download.range_unsupported');
            }
            $written = 0;
            $this->rangeReader->readAll($path, function (string $chunk) use (&$written, $length, $sink): void {
                if ($written >= $length) {
                    return;
                }
                $remaining = $length - $written;
                if (strlen($chunk) > $remaining) {
                    $chunk = substr($chunk, 0, $remaining);
                }
                $sink($chunk);
                $written += strlen($chunk);
            });

            return $written;
        }

        $this->rangeReader->ensureIndex($path, $this->getSize());

        return $this->rangeReader->readRange($path, $start, $length, $sink);
    }
}
