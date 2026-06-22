<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Service\Setup\SetupStateService;

/**
 * @brief Test double that skips setup bootstrap redirects.
 */
final class ConfirmedSetupStateService extends SetupStateService
{
    /**
     * @brief Skip Doctrine wiring in functional HTTP tests.
     */
    public function __construct()
    {
    }

    /**
     * @brief Always report confirmed admin bootstrap state.
     *
     * @return string
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function getSetupStatus(): string
    {
        return self::STATUS_CONFIRMED_ADMIN;
    }

    /**
     * @brief Always treat setup as complete.
     *
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function hasConfirmedAdminUser(): bool
    {
        return true;
    }

    /**
     * @brief Keep setup unlocked for HTTP home tests.
     *
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isLocked(): bool
    {
        return false;
    }
}
