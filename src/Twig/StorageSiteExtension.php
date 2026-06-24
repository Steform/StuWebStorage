<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Site\SiteAccessGateService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @brief Twig helpers for StuWebStorage site identity and platform flags.
 */
final class StorageSiteExtension extends AbstractExtension
{
    /**
     * @param string $siteTitle Application display title.
     * @param SiteAccessGateService $siteAccessGateService Platform settings service.
     */
    public function __construct(
        private readonly string $siteTitle,
        private readonly SiteAccessGateService $siteAccessGateService,
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
            new TwigFunction('storage_maintenance_mode_enabled', [$this, 'isMaintenanceModeEnabled']),
            new TwigFunction('storage_maintenance_message', [$this, 'getMaintenanceMessage']),
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

    /**
     * @brief Return whether public maintenance mode is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function isMaintenanceModeEnabled(): bool
    {
        return $this->siteAccessGateService->isMaintenanceModeEnabled();
    }

    /**
     * @brief Return optional maintenance message for visitors.
     *
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getMaintenanceMessage(): ?string
    {
        return $this->siteAccessGateService->getMaintenanceMessage();
    }
}
