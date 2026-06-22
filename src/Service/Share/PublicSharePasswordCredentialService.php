<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Security\PublicSharePasswordHolder;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @brief Hash and verify public-share passwords using the same hasher as application users.
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicSharePasswordCredentialService
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @brief Hash a plain password for persistence.
     * @param string $plain Plain password.
     * @return string Password hash.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function hashPlainPassword(string $plain): string
    {
        $holder = new PublicSharePasswordHolder();

        return $this->passwordHasher->hashPassword($holder, $plain);
    }

    /**
     * @brief Verify a plain password against stored hash.
     * @param string $plain User input.
     * @param string $storedHash Stored hash from DB.
     * @return bool True when valid.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function verify(string $plain, string $storedHash): bool
    {
        $holder = new PublicSharePasswordHolder();
        $holder->setPasswordHash($storedHash);

        return $this->passwordHasher->isPasswordValid($holder, $plain);
    }
}
