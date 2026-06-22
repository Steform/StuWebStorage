<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class LoginTotpChallenge.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\LoginTotpChallengeRepository')]
#[ORM\Table(name: 'login_totp_challenge')]
#[ORM\Index(columns: ['identity'], name: 'idx_login_totp_identity')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_login_totp_expires')]
class LoginTotpChallenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $identity;

    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lastSentAt;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $resendCount = 0;

    /**
     * @brief Build login TOTP challenge.
     * @param string $identity Login identity.
     * @param string $codeHash Hashed challenge code.
     * @param DateTimeImmutable $expiresAt Challenge expiration.
     * @param DateTimeImmutable $createdAt Challenge creation date.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(string $identity, string $codeHash, DateTimeImmutable $expiresAt, DateTimeImmutable $createdAt)
    {
        $this->identity = $identity;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->lastSentAt = $createdAt;
    }

    /**
     * @brief Get challenge identifier.
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
     * @brief Get login identity.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }

    /**
     * @brief Get hashed code.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    /**
     * @brief Get expiration date.
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
     * @brief Get creation date.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @brief Get consumption date if any.
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
     * @brief Check if challenge has been consumed.
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
     * @brief Check if challenge is expired.
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
     * @brief Mark challenge as consumed.
     * @param DateTimeImmutable $consumedAt Consumption date.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function consume(DateTimeImmutable $consumedAt): void
    {
        $this->consumedAt = $consumedAt;
    }

    /**
     * @brief Get last challenge send datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getLastSentAt(): DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    /**
     * @brief Get challenge resend counter.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getResendCount(): int
    {
        return $this->resendCount;
    }

    /**
     * @brief Check whether challenge can be resent.
     * @param DateTimeImmutable $now Current date.
     * @param int $cooldownSeconds Minimum cooldown in seconds.
     * @param int $maxResendCount Maximum resend attempts.
     * @return bool
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function canResend(DateTimeImmutable $now, int $cooldownSeconds, int $maxResendCount): bool
    {
        if ($this->consumedAt !== null || $this->expiresAt < $now || $this->resendCount >= $maxResendCount) {
            return false;
        }

        $elapsedSeconds = $now->getTimestamp() - $this->lastSentAt->getTimestamp();

        return $elapsedSeconds >= $cooldownSeconds;
    }

    /**
     * @brief Compute remaining cooldown seconds before another resend.
     * @param DateTimeImmutable $now Current date.
     * @param int $cooldownSeconds Minimum cooldown in seconds.
     * @return int
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function getRetryAfterSeconds(DateTimeImmutable $now, int $cooldownSeconds): int
    {
        $elapsedSeconds = $now->getTimestamp() - $this->lastSentAt->getTimestamp();
        $remaining = $cooldownSeconds - $elapsedSeconds;

        return max(0, $remaining);
    }

    /**
     * @brief Mark challenge as resent and update counters.
     * @param string $codeHash Newly hashed challenge code.
     * @param DateTimeImmutable $expiresAt New expiration datetime.
     * @param DateTimeImmutable $sentAt New send datetime.
     * @return void
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function resend(string $codeHash, DateTimeImmutable $expiresAt, DateTimeImmutable $sentAt): void
    {
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
        $this->lastSentAt = $sentAt;
        $this->resendCount++;
    }
}
