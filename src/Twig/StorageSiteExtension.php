<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @brief Twig helpers for StuWebStorage site identity.
 */
final class StorageSiteExtension extends AbstractExtension
{
    /**
     * @param string $siteTitle Application display title.
     */
    public function __construct(
        private readonly string $siteTitle,
    ) {
    }

    /**
     * @brief Register Twig functions.
     *
     * @param void No input parameter.
     * @return array<int, TwigFunction>
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('storage_site_title', [$this, 'getSiteTitle']),
            new TwigFunction('storage_favicon_href', [$this, 'getFaviconHref']),
            new TwigFunction('storage_favicon_type', [$this, 'getFaviconType']),
        ];
    }

    /**
     * @brief Return configured site title.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getSiteTitle(): string
    {
        return $this->siteTitle;
    }

    /**
     * @brief Return favicon asset path.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getFaviconHref(): string
    {
        return 'images/storage/favicon.svg';
    }

    /**
     * @brief Return favicon MIME type.
     *
     * @param void No input parameter.
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getFaviconType(): string
    {
        return 'image/svg+xml';
    }
}
