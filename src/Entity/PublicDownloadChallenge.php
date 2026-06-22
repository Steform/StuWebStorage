<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class PublicDownloadChallenge.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\PublicDownloadChallengeRepository')]
#[ORM\Table(name: 'public_download_challenge')]
class PublicDownloadChallenge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $publicToken;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $totpCode;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'boolean')]
    private bool $verified = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lastSentAt;

    #[ORM\Column(type: 'integer')]
    private int $resendCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $attemptCount = 0;

    /**
     * @brief Build public download email challenge.
     * @param string $publicToken Public token.
     * @param string $email Target email.
     * @param string $totpCode Verification code.
     * @param DateTimeImmutable $expiresAt Expiration date.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function __construct(string $publicToken, string $email, string $totpCode, DateTimeImmutable $expiresAt)
    {
        $now = new DateTimeImmutable();
        $this->publicToken = $publicToken;
        $this->email = $email;
        $this->totpCode = $totpCode;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
        $this->lastSentAt = $now;
    }

    /**
     * @brief Check if challenge can be verified.
     * @param string $code Input code.
     * @param DateTimeImmutable $now Current date.
     * @return bool
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function verify(string $code, DateTimeImmutable $now): bool
    {
        if ($this->expiresAt < $now || $this->verified) {
            return false;
        }

        if (!hash_equals($this->totpCode, $code)) {
            $this->attemptCount++;
            return false;
        }

        $this->verified = true;
        $this->attemptCount++;

        return true;
    }

    /**
     * @brief Validate TOTP without marking the challenge verified (for share-password gate).
     * @param string $code Input code.
     * @param DateTimeImmutable $now Current date.
     * @return bool True when code matches and challenge is still pending.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function verifyTotpCodeOnly(string $code, DateTimeImmutable $now): bool
    {
        if ($this->expiresAt < $now || $this->verified) {
            return false;
        }

        if (!hash_equals($this->totpCode, $code)) {
            $this->attemptCount++;

            return false;
        }

        return true;
    }

    /**
     * @brief Mark email verification complete after optional share-password step.
     * @param void No input parameter.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function markEmailChallengeVerified(): void
    {
        if ($this->verified) {
            return;
        }
        $this->verified = true;
        $this->attemptCount++;
    }

    /**
     * @brief Get challenge identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-04-24
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get linked public token.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    /**
     * @brief Get challenge target email.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @brief Get challenge expiration datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @brief Check if challenge was already verified.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /**
     * @brief Get challenge creation datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @brief Get last challenge send datetime.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-04-26
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
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getResendCount(): int
    {
        return $this->resendCount;
    }

    /**
     * @brief Get verify attempt counter.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    /**
     * @brief Check whether challenge can be resent.
     * @param DateTimeImmutable $now Current date.
     * @param int $cooldownSeconds Minimum cooldown in seconds.
     * @param int $maxResendCount Maximum resend attempts.
     * @return bool
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function canResend(DateTimeImmutable $now, int $cooldownSeconds, int $maxResendCount): bool
    {
        if ($this->verified || $this->expiresAt < $now || $this->resendCount >= $maxResendCount) {
            return false;
        }

        $elapsedSeconds = $now->getTimestamp() - $this->lastSentAt->getTimestamp();

        return $elapsedSeconds >= $cooldownSeconds;
    }

    /**
     * @brief Mark challenge as resent and update counters.
     * @param string $totpCode Newly generated code.
     * @param DateTimeImmutable $expiresAt New expiration datetime.
     * @param DateTimeImmutable $sentAt New send datetime.
     * @return void
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function resend(string $totpCode, DateTimeImmutable $expiresAt, DateTimeImmutable $sentAt): void
    {
        $this->totpCode = $totpCode;
        $this->expiresAt = $expiresAt;
        $this->lastSentAt = $sentAt;
        $this->resendCount++;
        $this->attemptCount = 0;
    }
}
