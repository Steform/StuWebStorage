<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\File\FilesStorageFeatureService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @brief Twig helpers for the files storage feature flag.
 */
final class FilesStorageFeatureExtension extends AbstractExtension
{
    /**
     * @param FilesStorageFeatureService $filesStorageFeatureService Files module feature flag.
     */
    public function __construct(
        private readonly FilesStorageFeatureService $filesStorageFeatureService,
    ) {
    }

    /**
     * @brief Register Twig functions exposed by this extension.
     *
     * @param void No input parameter.
     * @return TwigFunction[]
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('files_storage_enabled', [$this, 'isEnabled']),
        ];
    }

    /**
     * @brief Return whether the files storage module is enabled.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-09
     * @author Stephane H.
     */
    public function isEnabled(): bool
    {
        return $this->filesStorageFeatureService->isEnabled();
    }
}
