<?php

namespace App\Service\Auth;

/**
 * Service RememberMePolicyService.
 */
class RememberMePolicyService
{
    private int $lifetimeSeconds;

    /**
     * @brief Build remember-me policy service.
     * @param int $lifetimeSeconds Token lifetime in seconds.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function __construct(int $lifetimeSeconds = 2592000)
    {
        $this->lifetimeSeconds = $lifetimeSeconds;
    }

    /**
     * @brief Get remember-me token lifetime.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function getLifetimeSeconds(): int
    {
        return $this->lifetimeSeconds;
    }
}
