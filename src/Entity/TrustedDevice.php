<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class TrustedDevice.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\TrustedDeviceRepository')]
#[ORM\Table(name: 'trusted_device')]
#[ORM\UniqueConstraint(name: 'uniq_trusted_device_user_fingerprint', columns: ['user_id', 'device_fingerprint'])]
class TrustedDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $userId;

    #[ORM\Column(length: 255)]
    private string $deviceFingerprint;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $trustedUntil;

    /**
     * @brief Build trusted device aggregate.
     * @param int $userId User identifier.
     * @param string $deviceFingerprint Device fingerprint hash.
     * @param DateTimeImmutable $trustedUntil Trust expiration date.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function __construct(int $userId, string $deviceFingerprint, DateTimeImmutable $trustedUntil)
    {
        $this->userId = $userId;
        $this->deviceFingerprint = $deviceFingerprint;
        $this->trustedUntil = $trustedUntil;
    }

    /**
     * @brief Get user identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * @brief Get trusted device identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get device fingerprint.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getDeviceFingerprint(): string
    {
        return $this->deviceFingerprint;
    }

    /**
     * @brief Get trusted until datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getTrustedUntil(): DateTimeImmutable
    {
        return $this->trustedUntil;
    }

    /**
     * @brief Update trusted expiration date.
     * @param DateTimeImmutable $trustedUntil New expiration date.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function renew(DateTimeImmutable $trustedUntil): void
    {
        $this->trustedUntil = $trustedUntil;
    }

    /**
     * @brief Check if trusted device is expired.
     * @param DateTimeImmutable $now Current date time.
     * @return bool
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->trustedUntil < $now;
    }
}
