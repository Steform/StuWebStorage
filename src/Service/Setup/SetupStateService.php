<?php

namespace App\Service\Setup;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service SetupStateService.
 */
class SetupStateService
{
    private bool $locked = false;

    public const STATUS_NO_ADMIN = 'no_admin';
    public const STATUS_PENDING_ADMIN = 'pending_admin';
    public const STATUS_CONFIRMED_ADMIN = 'confirmed_admin';

    /**
     * @brief Build setup state service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @brief Check if setup mode is locked.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function isLocked(): bool
    {
        return $this->locked || $this->hasConfirmedAdminUser();
    }

    /**
     * @brief Lock setup mode after initialization.
     * @param void No input parameter.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function lock(): void
    {
        $this->locked = true;
    }

    /**
     * @brief Check if at least one admin user exists.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function hasAdminUser(): bool
    {
        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Check if at least one setup-confirmed admin exists.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function hasConfirmedAdminUser(): bool
    {
        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles(), true) && $user->isSetupConfirmed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Check if pending admin exists without final confirmation.
     * @param string $email Normalized email filter.
     * @return bool
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function hasPendingAdminUserByEmail(string $email): bool
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return false;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $normalizedEmail]);
        if (!$user instanceof User) {
            return false;
        }

        return in_array('ROLE_ADMIN', $user->getRoles(), true) && !$user->isSetupConfirmed();
    }

    /**
     * @brief Return setup status based on admin confirmation state.
     * @param void No input parameter.
     * @return string
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function getSetupStatus(): string
    {
        $hasConfirmedAdmin = $this->hasConfirmedAdminUser();
        if ($hasConfirmedAdmin) {
            return self::STATUS_CONFIRMED_ADMIN;
        }

        $hasAdmin = $this->hasAdminUser();
        if ($hasAdmin) {
            return self::STATUS_PENDING_ADMIN;
        }

        return self::STATUS_NO_ADMIN;
    }
}
