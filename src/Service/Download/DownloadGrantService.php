<?php

declare(strict_types=1);

namespace App\Service\Download;

use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Issue and validate long-lived HMAC download grants (stateless, ACL re-checked by callers).
 * @date 2026-11-02
 * @author Stephane H.
 */
final class DownloadGrantService
{
    public const KIND_FILE = 'file';

    public const KIND_ARCHIVE = 'archive';

    public const KIND_PUBLIC_FILE = 'public-file';

    public const KIND_PUBLIC_ARCHIVE = 'public-archive';

    public const ACTOR_USER = 'user';

    public const ACTOR_PUBLIC_CHALLENGE = 'public-challenge';

    /**
     * @brief Construct grant service.
     * @param string $appSecret HMAC signing secret.
     * @param int $ttlSeconds Grant lifetime in seconds.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly string $appSecret,
        private readonly int $ttlSeconds = 604800,
    ) {
    }

    /**
     * @brief Mint a signed grant token.
     * @param string $kind Resource kind constant.
     * @param string $resourceId Resource identifier (file id or archive token).
     * @param string $actorType Actor type constant.
     * @param string $actorId Actor identifier.
     * @param string $fingerprint Strong ETag or content fingerprint at issue time.
     * @return string Base64url-encoded grant.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function mint(
        string $kind,
        string $resourceId,
        string $actorType,
        string $actorId,
        string $fingerprint = '',
    ): string {
        $issuedAt = time();
        $expiresAt = $issuedAt + max(3600, $this->ttlSeconds);
        $payload = implode('|', [
            $kind,
            $resourceId,
            $actorType,
            $actorId,
            (string) $issuedAt,
            (string) $expiresAt,
            $fingerprint,
        ]);
        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        return $this->base64UrlEncode($payload.'|'.$signature);
    }

    /**
     * @brief Extract raw grant string from request query or header.
     * @param Request $request HTTP request.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function extractRawFromRequest(Request $request): string
    {
        $raw = trim((string) $request->query->get('g', ''));
        if ($raw === '') {
            $raw = trim((string) $request->headers->get('X-Download-Grant', ''));
        }

        return $raw;
    }

    /**
     * @brief Parse and validate a grant; returns null when invalid or expired.
     * @param string $token Encoded grant.
     * @param string|null $expectedKind Optional kind constraint.
     * @param string|null $expectedResourceId Optional resource constraint.
     * @return array{
     *     kind: string,
     *     resourceId: string,
     *     actorType: string,
     *     actorId: string,
     *     issuedAt: int,
     *     expiresAt: int,
     *     fingerprint: string
     * }|null
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function parse(string $token, ?string $expectedKind = null, ?string $expectedResourceId = null): ?array
    {
        if ($token === '') {
            return null;
        }

        $decoded = $this->base64UrlDecode($token);
        if ($decoded === null || $decoded === '') {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 8) {
            return null;
        }

        [$kind, $resourceId, $actorType, $actorId, $issuedAt, $expiresAt, $fingerprint, $signature] = $parts;
        $payload = implode('|', [$kind, $resourceId, $actorType, $actorId, $issuedAt, $expiresAt, $fingerprint]);
        $expected = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        if ((int) $expiresAt < time()) {
            return null;
        }

        if ($expectedKind !== null && $kind !== $expectedKind) {
            return null;
        }

        if ($expectedResourceId !== null && $resourceId !== $expectedResourceId) {
            return null;
        }

        return [
            'kind' => $kind,
            'resourceId' => $resourceId,
            'actorType' => $actorType,
            'actorId' => $actorId,
            'issuedAt' => (int) $issuedAt,
            'expiresAt' => (int) $expiresAt,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @brief Configured grant TTL in seconds.
     * @return int
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function getTtlSeconds(): int
    {
        return max(3600, $this->ttlSeconds);
    }

    /**
     * @brief Encode binary as base64url without padding.
     * @param string $raw Payload.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @brief Decode base64url payload.
     * @param string $encoded Encoded string.
     * @return string|null
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function base64UrlDecode(string $encoded): ?string
    {
        $padded = strtr($encoded, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        $padded .= str_repeat('=', $padLen);
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : null;
    }
}
