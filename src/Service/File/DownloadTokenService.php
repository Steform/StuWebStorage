<?php

declare(strict_types=1);

namespace App\Service\File;

use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Issue and validate short-lived HMAC download tokens for chunked delivery.
 * @author Stephane H.
 * @date 2026-07-07
 */
final class DownloadTokenService
{
    public function __construct(
        private readonly string $appSecret,
        private readonly int $ttlSeconds = 7200,
    ) {
    }

    /**
     * @brief Mint a signed download token for one shared file and actor.
     * @param int $sharedFileId Shared file identifier.
     * @param string $actorKey Stable actor key (user id or challenge id).
     * @param string $actorType Actor type token.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function mint(int $sharedFileId, string $actorKey, string $actorType): string
    {
        $expiresAt = time() + max(60, $this->ttlSeconds);
        $payload = implode('|', [
            (string) $sharedFileId,
            $actorType,
            $actorKey,
            (string) $expiresAt,
        ]);
        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        return base64_encode($payload.'|'.$signature);
    }

    /**
     * @brief Validate token from request query or header.
     * @param Request $request HTTP request.
     * @param int $sharedFileId Expected shared file id.
     * @param string $actorKey Expected actor key.
     * @param string $actorType Expected actor type.
     * @return bool
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function isValidForRequest(Request $request, int $sharedFileId, string $actorKey, string $actorType): bool
    {
        $raw = trim((string) $request->query->get('dt', ''));
        if ($raw === '') {
            $raw = trim((string) $request->headers->get('X-Download-Token', ''));
        }
        if ($raw === '') {
            return true;
        }

        return $this->isValid($raw, $sharedFileId, $actorKey, $actorType);
    }

    /**
     * @brief Validate a raw token value.
     * @param string $token Encoded token.
     * @param int $sharedFileId Expected shared file id.
     * @param string $actorKey Expected actor key.
     * @param string $actorType Expected actor type.
     * @return bool
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function isValid(string $token, int $sharedFileId, string $actorKey, string $actorType): bool
    {
        $decoded = base64_decode($token, true);
        if (!is_string($decoded) || $decoded === '') {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            return false;
        }

        [$fileId, $type, $actor, $expiresAt, $signature] = $parts;
        if ((int) $fileId !== $sharedFileId || $type !== $actorType || $actor !== $actorKey) {
            return false;
        }

        if ((int) $expiresAt < time()) {
            return false;
        }

        $payload = implode('|', [$fileId, $type, $actor, $expiresAt]);
        $expected = hash_hmac('sha256', $payload, $this->appSecret);

        return hash_equals($expected, $signature);
    }
}
