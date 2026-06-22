<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SiteAccessGateSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @brief Singleton settings for the public antibot access gate.
 */
#[ORM\Entity(repositoryClass: SiteAccessGateSettingsRepository::class)]
#[ORM\Table(name: 'site_access_gate_settings')]
class SiteAccessGateSettings
{
    public const DEFAULT_ANTIBOT_THRESHOLD = 50;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(type: 'smallint', options: ['default' => self::DEFAULT_ANTIBOT_THRESHOLD])]
    private int $antibotThreshold = self::DEFAULT_ANTIBOT_THRESHOLD;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $maintenanceModeEnabled = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $maintenanceMessage = null;

    /**
     * @brief Get row identifier.
     *
     * @param void No input parameter.
     * @return int|null
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Check whether the antibot gate is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @brief Enable or disable the antibot gate.
     *
     * @param bool $enabled Gate active flag.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * @brief Get antibot score threshold (0-100).
     *
     * @param void No input parameter.
     * @return int
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getAntibotThreshold(): int
    {
        return $this->antibotThreshold;
    }

    /**
     * @brief Set antibot score threshold (0-100).
     *
     * @param int $antibotThreshold Score threshold.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setAntibotThreshold(int $antibotThreshold): self
    {
        $this->antibotThreshold = $antibotThreshold;

        return $this;
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
        return $this->maintenanceModeEnabled;
    }

    /**
     * @brief Enable or disable public maintenance mode.
     *
     * @param bool $maintenanceModeEnabled Maintenance mode flag.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setMaintenanceModeEnabled(bool $maintenanceModeEnabled): self
    {
        $this->maintenanceModeEnabled = $maintenanceModeEnabled;

        return $this;
    }

    /**
     * @brief Get optional message shown during maintenance.
     *
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getMaintenanceMessage(): ?string
    {
        return $this->maintenanceMessage;
    }

    /**
     * @brief Set optional message shown during maintenance.
     *
     * @param string|null $maintenanceMessage Maintenance message.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setMaintenanceMessage(?string $maintenanceMessage): self
    {
        $this->maintenanceMessage = $maintenanceMessage;

        return $this;
    }
}
