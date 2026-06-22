<?php

declare(strict_types=1);

namespace App\Service\Share;

/**
 * @brief Symmetric encrypt/decrypt for owner-visible public-share password at rest (AES-256-GCM).
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicSharePasswordVault
{
    private const PREFIX = 'psp.v1.';

    public function __construct(
        private readonly string $secretMaterial,
    ) {
    }

    /**
     * @brief Encrypt plaintext password for DB storage.
     * @param string $plain Plain password.
     * @return string ASCII-safe ciphertext prefixed with version.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function encrypt(string $plain): string
    {
        $key = hash('sha256', self::PREFIX.$this->secretMaterial, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::PREFIX);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new \RuntimeException('public_share_password.encrypt_failed');
        }

        return self::PREFIX.base64_encode($iv.$tag.$cipher);
    }

    /**
     * @brief Decrypt ciphertext from DB for owner UI only.
     * @param string $stored Stored ciphertext.
     * @return string|null Plain password or null on failure.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', self::PREFIX.$this->secretMaterial, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::PREFIX);

        return is_string($plain) ? $plain : null;
    }
}
