<?php

declare(strict_types=1);

namespace App\Service\Download;

/**
 * @brief Byte-addressable downloadable resource with strong validators.
 * @date 2026-11-02
 * @author Stephane H.
 */
interface DownloadResourceInterface
{
    /**
     * @brief Total plaintext byte size of the resource.
     * @return int
     */
    public function getSize(): int;

    /**
     * @brief Strong ETag including surrounding quotes (RFC 9110).
     * @return string
     */
    public function getStrongEtag(): string;

    /**
     * @brief Last-Modified instant for validators.
     * @return \DateTimeImmutable
     */
    public function getLastModified(): \DateTimeImmutable;

    /**
     * @brief Suggested download file name.
     * @return string
     */
    public function getFileName(): string;

    /**
     * @brief MIME type for Content-Type.
     * @return string
     */
    public function getMimeType(): string;

    /**
     * @brief Whether HTTP Range is supported for this resource.
     * @return bool
     */
    public function supportsRanges(): bool;

    /**
     * @brief Emit a contiguous byte range into a writable sink callback.
     * @param int $start Inclusive start offset.
     * @param int $length Number of bytes to emit.
     * @param callable(string): void $sink Receives plaintext chunks (≤ 4 MiB each).
     * @return int Bytes actually written.
     */
    public function readRange(int $start, int $length, callable $sink): int;
}
