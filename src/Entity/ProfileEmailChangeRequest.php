<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class ProfileEmailChangeRequest.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\ProfileEmailChangeRequestRepository')]
#[ORM\Table(name: 'profile_email_change_request')]
#[ORM\Index(columns: ['user_id', 'consumed', 'expires_at'], name: 'idx_profile_email_change_active')]
class ProfileEmailChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $userId;

    #[ORM\Column(length: 190)]
    private string $newEmail;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'boolean')]
    private bool $consumed = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @brief Build profile email change request aggregate.
     * @param int $userId User identifier.
     * @param string $newEmail Requested new email.
     * @param DateTimeImmutable $expiresAt Expiration date.
     * @param DateTimeImmutable $createdAt Creation date.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(int $userId, string $newEmail, DateTimeImmutable $expiresAt, DateTimeImmutable $createdAt)
    {
        $this->userId = $userId;
        $this->newEmail = strtolower(trim($newEmail));
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    /**
     * @brief Get request identifier.
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
     * @brief Get requested email.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getNewEmail(): string
    {
        return $this->newEmail;
    }

    /**
     * @brief Get expiration datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @brief Check if request is consumed.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    /**
     * @brief Mark request as consumed.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function consume(): void
    {
        $this->consumed = true;
    }
}
