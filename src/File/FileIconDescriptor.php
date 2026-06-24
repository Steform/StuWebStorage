<?php

declare(strict_types=1);

namespace App\File;

/**
 * @brief Resolved UX icon metadata for a file extension.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final readonly class FileIconDescriptor
{
    /**
     * @param string $iconName Full UX icon name (e.g. vscode:file-type-word).
     * @param string $ariaLabel Accessible label for non-decorative usage.
     * @param FileIconCategory $category Resolved icon family.
     */
    public function __construct(
        public string $iconName,
        public string $ariaLabel,
        public FileIconCategory $category,
    ) {
    }
}
