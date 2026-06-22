<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\SiteAccessGateSettings;
use App\Repository\SiteAccessGateSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Resolve and persist site antibot gate settings.
 */
final class SiteAccessGateService
{
    /**
     * @param SiteAccessGateSettingsRepository $settingsRepository Gate settings repository.
     * @param SiteAccessGateSessionService $sessionService Gate session helper.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     */
    public function __construct(
        private readonly SiteAccessGateSettingsRepository $settingsRepository,
        private readonly SiteAccessGateSessionService $sessionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @brief Load singleton gate settings.
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
     * @brief Check whether antibot gate enforcement is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isGateEnabled(): bool
    {
        return $this->getSettings()->isEnabled();
    }

    /**
     * @brief Get configured antibot score threshold.
     *
     * @param void No input parameter.
     * @return int
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getAntibotThreshold(): int
    {
        return max(0, min(100, $this->getSettings()->getAntibotThreshold()));
    }

    /**
     * @brief Check whether current visitor may browse without seeing the gate.
     *
     * @param bool $adminBypass Whether caller has ROLE_ADMIN.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isBypassGranted(bool $adminBypass): bool
    {
        if ($adminBypass) {
            return true;
        }

        return $this->sessionService->isAccessGranted();
    }

    /**
     * @brief Grant antibot access in the current session.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function grantAccess(): void
    {
        $this->sessionService->grantAccess();
    }

    /**
     * @brief Update antibot gate settings from admin dashboard form.
     *
     * @param bool $enabled Gate enabled flag.
     * @param int $antibotThreshold Score threshold 0-100.
     * @return string|null Translation key on validation failure or null.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function updateSettings(bool $enabled, int $antibotThreshold): ?string
    {
        if ($antibotThreshold < 0 || $antibotThreshold > 100) {
            return 'storage.access_gate.error.invalid_threshold';
        }

        $settings = $this->getSettings();
        $settings->setEnabled($enabled);
        $settings->setAntibotThreshold($antibotThreshold);
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Check whether public maintenance mode is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-22
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
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getMaintenanceMessage(): ?string
    {
        $message = trim((string) $this->getSettings()->getMaintenanceMessage());

        return $message !== '' ? $message : null;
    }

    /**
     * @brief Update maintenance mode settings from admin dashboard form.
     *
     * @param bool $maintenanceModeEnabled Maintenance mode flag.
     * @param string $maintenanceMessage Optional visitor message.
     * @return null
     * @date 2026-06-22
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
}
