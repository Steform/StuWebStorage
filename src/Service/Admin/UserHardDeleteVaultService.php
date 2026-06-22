<?php

namespace App\Service\Admin;

use RuntimeException;

/**
 * Service UserHardDeleteVaultService.
 */
class UserHardDeleteVaultService
{
    private const CIPHER = 'aes-256-cbc';

    /**
     * @brief Build hard delete vault encryption service.
     * @param string $vaultKey Vault symmetric key.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(private readonly string $vaultKey)
    {
    }

    /**
     * @brief Encrypt and sign snapshot payload.
     * @param array<string, mixed> $payload Structured snapshot payload.
     * @return array{ciphertext: string, signature: string, algo: string, keyVersion: string}
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function encryptPayload(array $payload): array
    {
        if ($this->vaultKey === '') {
            throw new RuntimeException('admin.users.error.vault_key_missing');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('admin.users.error.snapshot_encode_failed');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength <= 0) {
            throw new RuntimeException('admin.users.error.snapshot_cipher_invalid');
        }

        $iv = random_bytes($ivLength);
        $cipherRaw = openssl_encrypt($json, self::CIPHER, $this->vaultKey, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipherRaw)) {
            throw new RuntimeException('admin.users.error.snapshot_encrypt_failed');
        }

        $blob = base64_encode($iv.$cipherRaw);
        $signature = hash_hmac('sha256', $blob, $this->vaultKey);

        return [
            'ciphertext' => $blob,
            'signature' => $signature,
            'algo' => self::CIPHER,
            'keyVersion' => 'v1',
        ];
    }

    /**
     * @brief Decrypt and verify snapshot payload.
     * @param string $ciphertext Encrypted blob.
     * @param string $signature Integrity signature.
     * @return array<string, mixed>
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function decryptPayload(string $ciphertext, string $signature): array
    {
        if ($this->vaultKey === '') {
            throw new RuntimeException('admin.users.error.vault_key_missing');
        }

        $expected = hash_hmac('sha256', $ciphertext, $this->vaultKey);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('admin.users.error.snapshot_signature_invalid');
        }

        $decoded = base64_decode($ciphertext, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('admin.users.error.snapshot_decode_failed');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength <= 0 || strlen($decoded) <= $ivLength) {
            throw new RuntimeException('admin.users.error.snapshot_decode_failed');
        }

        $iv = substr($decoded, 0, $ivLength);
        $cipherRaw = substr($decoded, $ivLength);
        $json = openssl_decrypt($cipherRaw, self::CIPHER, $this->vaultKey, OPENSSL_RAW_DATA, $iv);
        if (!is_string($json)) {
            throw new RuntimeException('admin.users.error.snapshot_decrypt_failed');
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new RuntimeException('admin.users.error.snapshot_decode_failed');
        }

        return $payload;
    }
}
