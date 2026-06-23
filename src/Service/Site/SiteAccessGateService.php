<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\SiteAccessGateSettings;
use App\Repository\SiteAccessGateSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Resolve and persist platform-wide site settings.
 */
final class SiteAccessGateService
{
    /**
     * @param SiteAccessGateSettingsRepository $settingsRepository Settings repository.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @param int $defaultAntibotThreshold Fallback antibot threshold from configuration.
     */
    public function __construct(
        private readonly SiteAccessGateSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $defaultAntibotThreshold,
    ) {
    }

    /**
     * @brief Load singleton platform settings.
     *
     * @param void No input parameter.
     * @return SiteAccessGateSettings
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getSettings(): SiteAccessGateSettings
    {
        return $this->settingsRepository->getOrCreateSingleton();
    }

    /**
     * @brief Check whether public maintenance mode is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function isMaintenanceModeEnabled(): bool
    {
        return $this->getSettings()->isMaintenanceModeEnabled();
    }

    /**
     * @brief Get optional maintenance message for visitors.
     *
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getMaintenanceMessage(): ?string
    {
        $message = trim((string) $this->getSettings()->getMaintenanceMessage());

        return $message !== '' ? $message : null;
    }

    /**
     * @brief Get configured antibot threshold for the homepage gate.
     *
     * @param void No input parameter.
     * @return int Score between 0 and 100.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getAntibotThreshold(): int
    {
        $threshold = $this->getSettings()->getAntibotThreshold();

        return $threshold > 0 ? $threshold : $this->defaultAntibotThreshold;
    }

    /**
     * @brief Update maintenance mode settings from admin dashboard form.
     *
     * @param bool $maintenanceModeEnabled Maintenance mode flag.
     * @param string $maintenanceMessage Optional visitor message.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function updateMaintenanceSettings(bool $maintenanceModeEnabled, string $maintenanceMessage): void
    {
        $normalizedMessage = trim($maintenanceMessage);
        $settings = $this->getSettings();
        $settings->setMaintenanceModeEnabled($maintenanceModeEnabled);
        $settings->setMaintenanceMessage($normalizedMessage !== '' ? $normalizedMessage : null);
        $this->entityManager->flush();
    }

    /**
     * @brief Update antibot threshold from admin dashboard form.
     *
     * @param int $antibotThreshold Score between 0 and 100.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function updateAntibotSettings(int $antibotThreshold): void
    {
        $settings = $this->getSettings();
        $settings->setAntibotThreshold($antibotThreshold);
        $this->entityManager->flush();
    }
}
