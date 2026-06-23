<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Service\Http\RequestSessionResolver;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @brief Signed session attestation after bot scoring or captcha success.
 */
final class StorageBotAttestationService
{
    public const METHOD_SIGNALS = 'signals';

    public const METHOD_CAPTCHA = 'captcha';

    private const SESSION_KEY = 'storage_bot_attestation';

    /**
     * @param RequestSessionResolver $requestSessionResolver Safe session accessor.
     * @param string $secret HMAC signing secret (kernel secret).
     * @param int $ttlSeconds Attestation lifetime in seconds.
     */
    public function __construct(
        private readonly RequestSessionResolver $requestSessionResolver,
        private readonly string $secret,
        private readonly int $ttlSeconds = 2700,
    ) {
    }

    /**
     * @brief Issue attestation after successful behavioural scoring.
     *
     * @param int $technicalScore Server-computed score (0-100).
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function issueFromSignals(int $technicalScore): void
    {
        $this->issue($technicalScore, self::METHOD_SIGNALS);
    }

    /**
     * @brief Issue attestation after successful captcha verification.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function issueFromCaptcha(): void
    {
        $this->issue(0, self::METHOD_CAPTCHA);
    }

    /**
     * @brief Check whether a valid attestation exists in session.
     *
     * @param void No input parameter.
     * @return bool True when attestation is present, signed, and not expired.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function isValid(): bool
    {
        return $this->getPayload() !== null;
    }

    /**
     * @brief Return stored technical score from attestation.
     *
     * @param void No input parameter.
     * @return int Score 0-100, or 0 when no attestation.
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function getScore(): int
    {
        $payload = $this->getPayload();

        return $payload !== null ? (int) ($payload['score'] ?? 0) : 0;
    }

    /**
     * @brief Persist signed attestation payload in session.
     *
     * @param int $score Technical score to store.
     * @param string $method Attestation method constant.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function issue(int $score, string $method): void
    {
        $session = $this->resolveSession();
        if ($session === null) {
            return;
        }

        $issuedAt = time();
        $payload = [
            'score' => max(0, min(100, $score)),
            'method' => $method,
            'issuedAt' => $issuedAt,
            'expiresAt' => $issuedAt + $this->ttlSeconds,
        ];
        $payload['signature'] = $this->sign($payload);
        $session->set(self::SESSION_KEY, $payload);
    }

    /**
     * @brief Read and validate attestation payload from session.
     *
     * @param void No input parameter.
     * @return array<string, mixed>|null Valid payload or null.
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function getPayload(): ?array
    {
        $session = $this->resolveSession();
        if ($session === null) {
            return null;
        }

        $stored = $session->get(self::SESSION_KEY);
        if (!is_array($stored)) {
            return null;
        }

        $signature = (string) ($stored['signature'] ?? '');
        $payload = $stored;
        unset($payload['signature']);

        if ($signature === '' || !hash_equals($this->sign($payload), $signature)) {
            $this->revoke();

            return null;
        }

        if ((int) ($payload['expiresAt'] ?? 0) < time()) {
            $this->revoke();

            return null;
        }

        return $stored;
    }

    /**
     * @brief Revoke attestation from session.
     *
     * @param void No input parameter.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function revoke(): void
    {
        $session = $this->resolveSession();
        if ($session === null) {
            return;
        }

        $session->remove(self::SESSION_KEY);
    }

    /**
     * @brief Resolve HTTP session when available.
     *
     * @param void No input parameter.
     * @return SessionInterface|null
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function resolveSession(): ?SessionInterface
    {
        return $this->requestSessionResolver->resolve();
    }

    /**
     * @brief Compute HMAC signature for attestation payload.
     *
     * @param array<string, mixed> $payload Payload without signature key.
     * @return string Hex HMAC digest.
     * @date 2026-06-23
     * @author Stephane H.
     */
    private function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, \JSON_THROW_ON_ERROR), $this->secret);
    }
}
