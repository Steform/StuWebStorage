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
