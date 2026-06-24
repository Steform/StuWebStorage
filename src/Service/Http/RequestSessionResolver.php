<?php

declare(strict_types=1);

namespace App\Service\Http;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @brief Safely resolves the HTTP session from the current main request.
 */
final class RequestSessionResolver
{
    /**
     * @param RequestStack $requestStack Symfony request stack.
     */
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @brief Return session when the main request supports and exposes it.
     *
     * @param void No input parameter.
     * @return SessionInterface|null Session or null when unavailable.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function resolve(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
