<?php

declare(strict_types=1);

namespace App\Service\Home;

use App\Service\Http\RequestSessionResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @brief Homepage public access session for antibot gate grants.
 */
final class HomeAccessSessionService
{
    public const SESSION_ACCESS_VALID_UNTIL = 'storage_home_access.valid_until';

    private const ACCESS_TTL_SECONDS = 1800;

    /**
     * @param RequestSessionResolver $requestSessionResolver Safe session accessor.
     * @param Security $security Security helper for role bypass checks.
     */
    public function __construct(
        private readonly RequestSessionResolver $requestSessionResolver,
        private readonly Security $security,
    ) {
    }

    /**
     * @brief Check whether the current user may bypass the homepage antibot gate.
     *
     * @param void No input parameter.
     * @return bool True for ROLE_ADMIN.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function isBypassGranted(): bool
    {
        try {
            return $this->security->isGranted('ROLE_ADMIN');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @brief Check whether antibot access is currently granted in session.
     *
     * @param void No input parameter.
     * @return bool
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function isAccessGranted(): bool
    {
        if ($this->isBypassGranted()) {
            return true;
        }

        $session = $this->getSession();
        if (!$session instanceof SessionInterface) {
            return false;
        }

        $validUntil = (int) $session->get(self::SESSION_ACCESS_VALID_UNTIL, 0);

        return $validUntil > time();
    }

    /**
     * @brief Grant homepage access for the configured TTL.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function grantAccess(): void
    {
        $session = $this->getSession();
        if (!$session instanceof SessionInterface) {
            return;
        }

        $session->set(self::SESSION_ACCESS_VALID_UNTIL, time() + self::ACCESS_TTL_SECONDS);
    }

    /**
     * @brief Get current session if available.
     *
     * @param void No input parameter.
     * @return SessionInterface|null
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function getSession(): ?SessionInterface
    {
        return $this->requestSessionResolver->resolve();
    }
}
