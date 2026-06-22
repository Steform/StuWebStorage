<?php

namespace App\File;

/**
 * @brief Public landing image preview: allowed file extensions (shared link page).
 * @date 2026-04-28
 * @author Stephane H.
 */
final class PublicImagePreviewSupport
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico'];

    /**
     * @brief Whether the file extension is eligible for a browser inline image preview.
     * @param string $extension Normalized or raw extension (leading dot is stripped).
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public static function isImageExtension(string $extension): bool
    {
        $ext = strtolower($extension);
        if (str_starts_with($ext, '.')) {
            $ext = substr($ext, 1);
        }

        return in_array($ext, self::EXTENSIONS, true);
    }
}
