<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @brief In-memory user provider for functional HTTP tests without database seeding.
 */
final class TestUserProvider implements UserProviderInterface
{
    /** @var array<string, User> */
    private static array $users = [];

    /**
     * @brief Register one test user keyed by email.
     *
     * @param User $user User entity to expose to security.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public static function register(User $user): void
    {
        self::$users[strtolower($user->getEmail())] = $user;
    }

    /**
     * @brief Clear registered test users between tests.
     *
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public static function reset(): void
    {
        self::$users = [];
    }

    /**
     * @brief Load user by email identifier.
     *
     * @param string $identifier User email.
     * @return UserInterface
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $normalized = strtolower(trim($identifier));
        $user = self::$users[$normalized] ?? null;
        if (!$user instanceof User) {
            throw new UserNotFoundException(sprintf('Test user "%s" was not registered.', $identifier));
        }

        return $user;
    }

    /**
     * @brief Refresh user from in-memory registry.
     *
     * @param UserInterface $user Current security user.
     * @return UserInterface
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    /**
     * @brief Whether this provider supports the given user class.
     *
     * @param class-string $class User class name.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function supportsClass(string $class): bool
    {
        return $class === User::class || is_subclass_of($class, User::class);
    }
}
