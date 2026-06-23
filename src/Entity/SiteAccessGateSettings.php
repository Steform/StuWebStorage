<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SiteAccessGateSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @brief Singleton platform settings (maintenance mode and antibot threshold).
 */
#[ORM\Entity(repositoryClass: SiteAccessGateSettingsRepository::class)]
#[ORM\Table(name: 'site_access_gate_settings')]
class SiteAccessGateSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $maintenanceModeEnabled = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $maintenanceMessage = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 50])]
    private int $antibotThreshold = 50;

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
     * @brief Check whether public maintenance mode is active.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-23
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
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function setMaintenanceModeEnabled(bool $maintenanceModeEnabled): self
    {
        $this->maintenanceModeEnabled = $maintenanceModeEnabled;

        return $this;
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
        return $this->maintenanceMessage;
    }

    /**
     * @brief Set optional maintenance message for visitors.
     *
     * @param string|null $maintenanceMessage Maintenance message.
     * @return self
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function setMaintenanceMessage(?string $maintenanceMessage): self
    {
        $this->maintenanceMessage = $maintenanceMessage;

        return $this;
    }

    /**
     * @brief Get minimum behavioural score required to pass the homepage antibot gate.
     *
     * @param void No input parameter.
     * @return int Score between 0 and 100.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getAntibotThreshold(): int
    {
        return $this->antibotThreshold;
    }

    /**
     * @brief Set minimum behavioural score required to pass the homepage antibot gate.
     *
     * @param int $antibotThreshold Score between 0 and 100.
     * @return self
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function setAntibotThreshold(int $antibotThreshold): self
    {
        $this->antibotThreshold = max(0, min(100, $antibotThreshold));

        return $this;
    }
}
