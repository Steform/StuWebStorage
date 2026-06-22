<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * @brief Central feature flag for the encrypted files / cloud storage module.
 */
final class FilesStorageFeatureService
{
    /**
     * @param bool $enabled Whether the files storage module is active.
     */
    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    /**
     * @brief Return whether the files storage module is enabled from configuration.
     *
     * @param void No input parameter.
     * @return bool True when APP_FILES_STORAGE_ENABLED is truthy.
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
