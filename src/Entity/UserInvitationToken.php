<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class UserInvitationToken.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\UserInvitationTokenRepository')]
#[ORM\Table(name: 'user_invitation_token')]
#[ORM\Index(columns: ['user_id'], name: 'idx_invitation_user')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_invitation_expires')]
class UserInvitationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $userId;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'integer')]
    private int $invitedByUserId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    /**
     * @brief Build invitation token aggregate.
     * @param int $userId Invited user identifier.
     * @param string $email Invited user email.
     * @param string $tokenHash Hashed activation token.
     * @param int $invitedByUserId Inviter user identifier.
     * @param DateTimeImmutable $createdAt Creation date.
     * @param DateTimeImmutable $expiresAt Expiration date.
     * @param string $locale Invitation email locale code.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        int $userId,
        string $email,
        string $tokenHash,
        int $invitedByUserId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
        string $locale = 'en'
    ) {
        $this->userId = $userId;
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->invitedByUserId = $invitedByUserId;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->locale = $locale;
    }

    /**
     * @brief Get invited user identifier.
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
     * @brief Get invited email.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @brief Get token hash.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * @brief Get inviter identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getInvitedByUserId(): int
    {
        return $this->invitedByUserId;
    }

    /**
     * @brief Get token expiration date.
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
     * @brief Get token consumed date.
     * @param void No input parameter.
     * @return DateTimeImmutable|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getConsumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /**
     * @brief Get invitation email locale code.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @brief Check whether token is consumed.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    /**
     * @brief Check whether token is expired.
     * @param DateTimeImmutable $now Current datetime.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    /**
     * @brief Mark token as consumed.
     * @param DateTimeImmutable $consumedAt Consumption date.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function consume(DateTimeImmutable $consumedAt): void
    {
        $this->consumedAt = $consumedAt;
    }
}
