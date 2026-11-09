<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * @brief Read free and total disk space for the filesystem hosting encrypted uploads.
 */
final class ServerDiskSpaceService
{
    /**
     * @param string $projectDir Symfony project root directory.
     */
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @brief Build disk usage snapshot for the admin settings dashboard.
     *
     * @param void No input parameter.
     * @return array{
     *     available: bool,
     *     path: string,
     *     freeBytes: int|null,
     *     totalBytes: int|null,
     *     usedBytes: int|null,
     *     usedPercent: int|null
     * }
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function buildSnapshot(): array
    {
        $path = $this->resolveProbePath();
        $freeBytes = @disk_free_space($path);
        $totalBytes = @disk_total_space($path);

        if ($freeBytes === false || $totalBytes === false || $totalBytes <= 0) {
            return [
                'available' => false,
                'path' => $path,
                'freeBytes' => null,
                'totalBytes' => null,
                'usedBytes' => null,
                'usedPercent' => null,
            ];
        }

        $freeBytes = (int) $freeBytes;
        $totalBytes = (int) $totalBytes;
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercent = (int) round(($usedBytes / $totalBytes) * 100);

        return [
            'available' => true,
            'path' => $path,
            'freeBytes' => $freeBytes,
            'totalBytes' => $totalBytes,
            'usedBytes' => $usedBytes,
            'usedPercent' => min(100, max(0, $usedPercent)),
        ];
    }

    public const EXCEPTION_DISK_FULL = 'storage_disk.full';

    /**
     * @brief Ensure the storage filesystem can absorb an upload+encrypt peak (~2× plaintext).
     * @param int $plainBytes Declared plaintext size.
     * @return void
     * @throws \RuntimeException When free space is insufficient.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function assertFreeBytesForUploadEncryptPeak(int $plainBytes): void
    {
        // Before any bytes land: need room for plaintext part + cipher output.
        $this->assertFreeBytes((int) ($plainBytes * 2 + (16 * 1024 * 1024)));
    }

    /**
     * @brief Ensure free space for remaining cipher writes when plaintext is already on disk.
     * @param int $remainingPlainBytes Plaintext bytes still to encrypt.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function assertFreeBytesForRemainingCipher(int $remainingPlainBytes): void
    {
        $this->assertFreeBytes((int) (max(0, $remainingPlainBytes) + (16 * 1024 * 1024)));
    }

    /**
     * @brief Throw when free disk bytes are below the required amount.
     * @param int $neededBytes Required free bytes.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function assertFreeBytes(int $neededBytes): void
    {
        if ($neededBytes < 1) {
            return;
        }
        $snapshot = $this->buildSnapshot();
        if (!$snapshot['available'] || $snapshot['freeBytes'] === null) {
            return;
        }
        if ((int) $snapshot['freeBytes'] < $neededBytes) {
            throw new \RuntimeException(self::EXCEPTION_DISK_FULL);
        }
    }

    /**
     * @brief Resolve an existing directory used to query the storage filesystem.
     *
     * @param void No input parameter.
     * @return string Absolute path on disk.
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function resolveProbePath(): string
    {
        $candidates = [
            $this->projectDir.'/var/shared',
            $this->projectDir.'/var',
            $this->projectDir,
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $this->projectDir;
    }
}
