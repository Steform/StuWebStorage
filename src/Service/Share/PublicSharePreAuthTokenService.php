<?php

declare(strict_types=1);

namespace App\Service\Share;

/**
 * @brief Signed short-lived token proving email TOTP succeeded before share-password check.
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicSharePreAuthTokenService
{
    public function __construct(
        private readonly string $signingSecret,
        private readonly int $ttlSeconds = 900,
    ) {
    }

    /**
     * @brief Build a pre-authorization token bound to challenge and public token.
     * @param int $challengeId Challenge primary key.
     * @param string $publicToken Public asset token.
     * @return string Opaque signed token (ASCII).
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function mint(int $challengeId, string $publicToken): string
    {
        $expiresAt = time() + $this->ttlSeconds;
        $payload = $challengeId.'|'.$publicToken.'|'.$expiresAt;
        $sig = hash_hmac('sha256', $payload, $this->signingSecret);

        return rtrim(strtr(base64_encode($payload.'|'.$sig), '+/', '-_'), '=');
    }

    /**
     * @brief Parse and verify signature + expiry; returns structured payload or null.
     * @param string $token Token from client.
     * @return array{challengeId: int, publicToken: string}|null
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function verify(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $padded = $token;
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        $lastPipe = strrpos($decoded, '|');
        if ($lastPipe === false) {
            return null;
        }
        $payloadPart = substr($decoded, 0, $lastPipe);
        $sig = substr($decoded, $lastPipe + 1);
        $expectedSig = hash_hmac('sha256', $payloadPart, $this->signingSecret);
        if ($sig === '' || !hash_equals($expectedSig, $sig)) {
            return null;
        }
        $parts = explode('|', $payloadPart);
        if (count($parts) !== 3) {
            return null;
        }
        $challengeId = (int) $parts[0];
        $publicToken = $parts[1];
        $expiresAt = (int) $parts[2];
        if ($challengeId < 1 || $publicToken === '' || $expiresAt < time()) {
            return null;
        }

        return ['challengeId' => $challengeId, 'publicToken' => $publicToken];
    }
}
