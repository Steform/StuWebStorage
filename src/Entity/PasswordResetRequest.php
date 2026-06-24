<?php

namespace App\Entity;

use App\Repository\PasswordResetRequestRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class PasswordResetRequest.
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
#[ORM\Table(name: 'password_reset_request')]
class PasswordResetRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $userId;

    #[ORM\Column(length: 255, unique: true)]
    private string $token;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'boolean')]
    private bool $consumed = false;

    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    /**
     * @brief Build password reset request.
     * @param int $userId User identifier.
     * @param string $token Reset token hash.
     * @param DateTimeImmutable $expiresAt Token expiration date.
     * @param string $locale Reset email locale code.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function __construct(
        int $userId,
        string $token,
        DateTimeImmutable $expiresAt,
        string $locale = 'en'
    ) {
        $this->userId = $userId;
        $this->token = $token;
        $this->expiresAt = $expiresAt;
        $this->locale = $locale;
    }

    /**
     * @brief Get user identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * @brief Get token hash.
     * @param void No input parameter.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @brief Get expiration date.
     * @param void No input parameter.
     * @return DateTimeImmutable
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @brief Check whether request is consumed.
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    /**
     * @brief Get reset email locale code.
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
     * @brief Mark request as consumed.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function consume(): void
    {
        $this->consumed = true;
    }
}
