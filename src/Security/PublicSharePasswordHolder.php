<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * @brief Lightweight user adapter so UserPasswordHasherInterface can hash or verify public-share passwords.
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicSharePasswordHolder implements PasswordAuthenticatedUserInterface
{
    public function __construct(
        private string $hashedPassword = '',
    ) {
    }

    public function getPassword(): ?string
    {
        return $this->hashedPassword !== '' ? $this->hashedPassword : null;
    }

    public function setPasswordHash(string $hash): void
    {
        $this->hashedPassword = $hash;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'public_share_gate';
    }
}
