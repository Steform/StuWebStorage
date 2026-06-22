<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class PasswordResetRequest.
 */
#[ORM\Entity]
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

    /**
     * @brief Build password reset request.
     * @param int $userId User identifier.
     * @param string $token Reset token.
     * @param DateTimeImmutable $expiresAt Token expiration date.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function __construct(int $userId, string $token, DateTimeImmutable $expiresAt)
    {
        $this->userId = $userId;
        $this->token = $token;
        $this->expiresAt = $expiresAt;
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
