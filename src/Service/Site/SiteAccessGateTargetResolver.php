<?php

declare(strict_types=1);

namespace App\Service\Site;

/**
 * @brief Sanitize post-gate redirect targets to local application paths.
 */
final class SiteAccessGateTargetResolver
{
    /**
     * @brief Resolve safe redirect target from raw query value.
     *
     * @param string|null $rawTarget Raw target path or URL fragment.
     * @return string Safe local path defaulting to home.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function resolve(?string $rawTarget): string
    {
        $target = trim((string) $rawTarget);
        if ($target === '' || !str_starts_with($target, '/')) {
            return '/';
        }

        if (str_starts_with($target, '//') || str_contains($target, '://')) {
            return '/';
        }

        if (str_starts_with($target, '/access-gate')) {
            return '/';
        }

        return $target;
    }
}
