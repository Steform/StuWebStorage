<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * @brief Twig extension for bug report admin display helpers.
 * @author Stephane H.
 * @date 2026-06-26
 */
final class BugReportExtension extends AbstractExtension
{
    private const EMPTY_PLACEHOLDER = '—';

    /**
     * @brief Declare Twig filters exposed by this extension.
     * @return TwigFilter[] Registered filters.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('bug_report_excerpt', [$this, 'excerpt']),
            new TwigFilter('bug_report_tooltip_html', [$this, 'tooltipHtml']),
        ];
    }

    /**
     * @brief Truncate bug report text for table excerpts.
     * @param string|null $text Source text.
     * @param int $length Maximum visible length.
     * @return string Excerpt or placeholder when empty.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function excerpt(?string $text, int $length = 80): string
    {
        $normalized = trim((string) $text);
        if ($normalized === '') {
            return self::EMPTY_PLACEHOLDER;
        }

        if (mb_strlen($normalized) <= $length) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $length).'…';
    }

    /**
     * @brief Escape bug report text for Bootstrap HTML tooltips.
     * @param string|null $text Source text.
     * @return string HTML-safe tooltip body.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function tooltipHtml(?string $text): string
    {
        $normalized = trim((string) $text);
        if ($normalized === '') {
            return self::EMPTY_PLACEHOLDER;
        }

        return nl2br(htmlspecialchars($normalized, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
    }
}
