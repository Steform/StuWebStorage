<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SiteAccessGateSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @brief Singleton settings for the public site access gate.
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
    private bool $enabled = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $gateMessage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bypassNote = null;

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
     * @brief Check whether the access gate is active.
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
     * @brief Enable or disable the access gate.
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
     * @brief Get visitor message shown on the gate page.
     *
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getGateMessage(): ?string
    {
        return $this->gateMessage;
    }

    /**
     * @brief Set visitor message shown on the gate page.
     *
     * @param string|null $gateMessage Gate message.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setGateMessage(?string $gateMessage): self
    {
        $this->gateMessage = $gateMessage;

        return $this;
    }

    /**
     * @brief Get secret bypass note required to unlock the site.
     *
     * @param void No input parameter.
     * @return string|null
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getBypassNote(): ?string
    {
        return $this->bypassNote;
    }

    /**
     * @brief Set secret bypass note required to unlock the site.
     *
     * @param string|null $bypassNote Bypass note.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setBypassNote(?string $bypassNote): self
    {
        $this->bypassNote = $bypassNote;

        return $this;
    }
}
