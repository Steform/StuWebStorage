<?php

declare(strict_types=1);

namespace App\Service\Site;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @brief Session-backed access grant for the public site gate.
 */
final class SiteAccessGateSessionService
{
    private const SESSION_VALID_UNTIL_KEY = 'storage_access_gate.valid_until';

    private const ACCESS_TTL_SECONDS = 1800;

    /**
     * @param RequestStack $requestStack Request stack.
     */
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @brief Check whether current session has a valid gate grant.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function isAccessGranted(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            return false;
        }

        $validUntil = (int) $request->getSession()->get(self::SESSION_VALID_UNTIL_KEY, 0);

        return $validUntil > time();
    }

    /**
     * @brief Grant gate access for the configured TTL.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function grantAccess(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            return;
        }

        $request->getSession()->set(self::SESSION_VALID_UNTIL_KEY, time() + self::ACCESS_TTL_SECONDS);
    }

    /**
     * @brief Revoke gate access from current session.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function revokeAccess(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request || !$request->hasSession()) {
            return;
        }

        $request->getSession()->remove(self::SESSION_VALID_UNTIL_KEY);
    }
}
