<?php

declare(strict_types=1);

namespace App\Service\Home;

/**
 * @brief Validates safe redirect targets for the homepage antibot gate.
 */
final class HomeAccessTargetResolver
{
    /**
     * @brief Resolve and sanitize post-gate redirect target path.
     *
     * @param string|null $target Raw target query value.
     * @return string Safe path, defaulting to /.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function resolveSafeTarget(?string $target): string
    {
        $candidate = trim((string) $target);
        if ($candidate === '' || $candidate !== '/') {
            return '/';
        }

        if (str_contains($candidate, '//') || str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
            return '/';
        }

        return $candidate;
    }
}
