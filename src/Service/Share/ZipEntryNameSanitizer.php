<?php

namespace App\Service\Share;

/**
 * Sanitizes ZIP entry paths to mitigate Zip Slip (CWE-22) when archives are extracted by clients.
 */
final class ZipEntryNameSanitizer
{
    /**
     * @brief Normalize a relative ZIP entry path: drop traversal segments, strip NULs, flatten separators.
     * @param string $rawPath Raw relative path (may include folder prefixes and original file name).
     * @param int $fallbackFileId Fallback id used when the path becomes empty after sanitization.
     * @return string Safe relative path using forward slashes only.
     * @date 2026-05-02
     * @author Stephane H.
     */
    public static function sanitizeEntryPath(string $rawPath, int $fallbackFileId): string
    {
        $normalized = str_replace('\\', '/', $rawPath);
        $normalized = str_replace("\0", '', $normalized);
        $normalized = trim($normalized);
        $normalized = ltrim($normalized, '/');
        $parts = explode('/', $normalized);
        $safe = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $segment = str_replace(['..', "\0"], '', $part);
            $segment = preg_replace('/[^\p{L}\p{N}._()\- ]+/u', '_', $segment);
            $segment = trim((string) $segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $segment = '_';
            }
            $safe[] = $segment;
        }
        if ($safe === []) {
            return 'file_'.$fallbackFileId.'.bin';
        }

        return implode('/', $safe);
    }
}
