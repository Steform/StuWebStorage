<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class User.
 */
#[ORM\Entity(repositoryClass: 'App\Repository\UserRepository')]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(length: 100)]
    private string $pseudonym = '';

    #[ORM\Column(type: 'boolean')]
    private bool $totpEnabled = true;

    #[ORM\Column(type: 'boolean')]
    private bool $setupConfirmed = false;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'boolean')]
    private bool $passwordResetRequired = false;

    #[ORM\Column(type: 'integer')]
    private int $sessionVersion = 1;

    /**
     * @var array<int, string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $storageQuotaBytes = null;

    /**
     * @brief Get user identifier.
     * @param void No input parameter.
     * @return int|null
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @brief Get user email.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @brief Get unique user identifier for security.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @brief Set user email.
     * @param string $email User email.
     * @return self
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @brief Get hashed password.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @brief Set hashed password.
     * @param string $password Hashed password.
     * @return self
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @brief Get user pseudonym.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getPseudonym(): string
    {
        return $this->pseudonym;
    }

    /**
     * @brief Set user pseudonym.
     * @param string $pseudonym User pseudonym.
     * @return self
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function setPseudonym(string $pseudonym): self
    {
        $this->pseudonym = $pseudonym;

        return $this;
    }

    /**
     * @brief Check if TOTP is enabled.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    /**
     * @brief Set TOTP enabled flag.
     * @param bool $totpEnabled TOTP status.
     * @return self
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function setTotpEnabled(bool $totpEnabled): self
    {
        $this->totpEnabled = $totpEnabled;

        return $this;
    }

    /**
     * @brief Check whether setup is confirmed for the user.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isSetupConfirmed(): bool
    {
        return $this->setupConfirmed;
    }

    /**
     * @brief Set setup confirmation flag.
     * @param bool $setupConfirmed Setup confirmation status.
     * @return self
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function setSetupConfirmed(bool $setupConfirmed): self
    {
        $this->setupConfirmed = $setupConfirmed;

        return $this;
    }

    /**
     * @brief Check whether account is active.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @brief Set account active flag.
     * @param bool $active Account active flag.
     * @return self
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @brief Check whether password reset is required.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isPasswordResetRequired(): bool
    {
        return $this->passwordResetRequired;
    }

    /**
     * @brief Set password reset required flag.
     * @param bool $passwordResetRequired Password reset requirement.
     * @return self
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function setPasswordResetRequired(bool $passwordResetRequired): self
    {
        $this->passwordResetRequired = $passwordResetRequired;

        return $this;
    }

    /**
     * @brief Get user session version for invalidation strategy.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getSessionVersion(): int
    {
        return $this->sessionVersion;
    }

    /**
     * @brief Bump session version to invalidate active sessions.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function bumpSessionVersion(): void
    {
        $this->sessionVersion++;
    }

    /**
     * @brief Return granted roles with default ROLE_USER.
     * @param void No input parameter.
     * @return array<int, string>
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @brief Set granted roles.
     * @param array<int, string> $roles Granted role list.
     * @return self
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @brief Get per-user storage quota in bytes (null inherits platform default).
     * @param void No input parameter.
     * @return int|null
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getStorageQuotaBytes(): ?int
    {
        return $this->storageQuotaBytes;
    }

    /**
     * @brief Set per-user storage quota in bytes (null inherits platform default, 0 unlimited).
     * @param int|null $storageQuotaBytes Quota bytes or null.
     * @return self
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function setStorageQuotaBytes(?int $storageQuotaBytes): self
    {
        $this->storageQuotaBytes = $storageQuotaBytes;

        return $this;
    }

    /**
     * @brief Remove temporary sensitive data.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function eraseCredentials(): void
    {
    }
}
