<?php

namespace App\Service\File;

use RuntimeException;

/**
 * Service FileEncryptionService.
 */
class FileEncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    /** @brief Magic bytes for on-disk format v2 (segmented CBC, binary). */
    private const STORAGE_MAGIC_V2 = 'CVF2';

    /** @brief Plaintext chunk size for streaming encrypt (4 MiB). */
    private const PLAIN_CHUNK_BYTES = 4194304;

    /** @brief Maximum plaintext buffered by decryptFromStorage for legacy callers (v2 path). */
    private const DECRYPT_STRING_MAX_BYTES = 52428800;

    public function __construct(private readonly string $encryptionKey)
    {
    }

    /**
     * @brief Encrypt file content payload (legacy v1: single base64 blob).
     * @param string $content Plain content.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function encrypt(string $content): string
    {
        if ($this->encryptionKey === '') {
            throw new RuntimeException('file.encryption.key_missing');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength <= 0) {
            throw new RuntimeException('file.encryption.cipher_invalid');
        }

        $iv = random_bytes($ivLength);
        $cipherText = openssl_encrypt($content, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipherText)) {
            throw new RuntimeException('file.encryption.failed');
        }

        return base64_encode($iv.$cipherText);
    }

    /**
     * @brief Encrypt a plaintext file on disk into v2 binary storage without loading whole file in RAM.
     * @param string $plainSourcePath Readable plaintext path.
     * @param string $outputEncryptedPath Writable destination path.
     * @return int Plaintext byte length written into header.
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function encryptPlainFileToV2Storage(string $plainSourcePath, string $outputEncryptedPath): int
    {
        if ($this->encryptionKey === '') {
            throw new RuntimeException('file.encryption.key_missing');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength <= 0) {
            throw new RuntimeException('file.encryption.cipher_invalid');
        }

        if ($plainSourcePath === '' || !is_readable($plainSourcePath)) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        $plainTotal = filesize($plainSourcePath);
        if ($plainTotal === false || $plainTotal < 0) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        $in = fopen($plainSourcePath, 'rb');
        if ($in === false) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        $out = fopen($outputEncryptedPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('file.encryption.storage_failed');
        }

        try {
            fwrite($out, self::STORAGE_MAGIC_V2);
            fwrite($out, chr(1));
            fwrite($out, "\0\0\0");
            fwrite($out, pack('J', $plainTotal));

            $remaining = $plainTotal;
            while ($remaining > 0) {
                $toRead = (int) min(self::PLAIN_CHUNK_BYTES, $remaining);
                $plainChunk = fread($in, $toRead);
                if (!is_string($plainChunk) || $plainChunk === '') {
                    throw new RuntimeException('file.encryption.storage_unreadable');
                }
                $remaining -= strlen($plainChunk);
                $iv = random_bytes($ivLength);
                $cipherText = openssl_encrypt($plainChunk, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
                if (!is_string($cipherText)) {
                    throw new RuntimeException('file.encryption.failed');
                }
                $this->writeBinarySegment($out, $iv, $cipherText);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $plainTotal;
    }

    /**
     * @brief Write one ciphertext segment: iv length, iv, cipher length, cipher.
     * @param resource $out Binary output handle.
     * @param string $iv Initialization vector.
     * @param string $cipher Raw ciphertext.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function writeBinarySegment($out, string $iv, string $cipher): void
    {
        $ivLen = strlen($iv);
        if ($ivLen > 65535) {
            throw new RuntimeException('file.encryption.segment_invalid');
        }
        fwrite($out, pack('n', $ivLen));
        fwrite($out, $iv);
        $cl = strlen($cipher);
        fwrite($out, pack('N', $cl));
        fwrite($out, $cipher);
    }

    /**
     * @brief Whether storage file uses v2 segmented binary format.
     * @param string $storagePath Absolute path.
     * @return bool
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function isV2StorageFormat(string $storagePath): bool
    {
        if ($storagePath === '' || !is_readable($storagePath)) {
            return false;
        }
        $h = @fopen($storagePath, 'rb');
        if ($h === false) {
            return false;
        }
        $magic = fread($h, 4);
        fclose($h);

        return $magic === self::STORAGE_MAGIC_V2;
    }

    /**
     * @brief Stream decrypted plaintext bytes to stdout (download handlers).
     * @param string $storagePath Encrypted file path.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function streamDecryptStorageToStdout(string $storagePath): void
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('file.encryption.output_failed');
        }
        try {
            $this->streamDecryptStorageToHandle($storagePath, $out);
        } finally {
            fclose($out);
        }
    }

    /**
     * @brief Copy decrypted plaintext into a binary handle (bounded memory).
     * @param string $storagePath Encrypted file path.
     * @param resource $target Writable binary stream.
     * @return int Total plaintext bytes written.
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function streamDecryptStorageToHandle(string $storagePath, $target): int
    {
        if ($storagePath === '' || !is_readable($storagePath)) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        $fh = fopen($storagePath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        try {
            $magic = fread($fh, 4);
            if ($magic === self::STORAGE_MAGIC_V2) {
                return $this->decryptV2BodyFromHandleAfterMagic($fh, $target);
            }

            rewind($fh);
            $encryptedContent = stream_get_contents($fh);
            if (!is_string($encryptedContent) || $encryptedContent === '') {
                throw new RuntimeException('file.encryption.storage_empty');
            }

            $plain = $this->decrypt($encryptedContent);
            fwrite($target, $plain);

            return strlen($plain);
        } finally {
            fclose($fh);
        }
    }

    /**
     * @brief Decrypt v2 payload after magic; handle positioned after first 4 bytes.
     * @param resource $fh Open read handle positioned after magic bytes.
     * @param resource $target Writable handle.
     * @return int Plain bytes written.
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function decryptV2BodyFromHandleAfterMagic($fh, $target): int
    {
        $version = fread($fh, 1);
        if ($version === false || $version === '') {
            throw new RuntimeException('file.encryption.invalid_payload');
        }
        fread($fh, 3);
        $lenBin = fread($fh, 8);
        if ($lenBin === false || strlen($lenBin) !== 8) {
            throw new RuntimeException('file.encryption.invalid_payload');
        }
        $plainExpected = unpack('J', $lenBin);
        $expectedTotal = is_array($plainExpected) ? (int) ($plainExpected[1] ?? 0) : 0;

        return $this->decryptV2Segments($fh, $target, $expectedTotal);
    }

    /**
     * @brief Decrypt chained segments until EOF; verifies total length when possible.
     * @param resource $fh Cipher file handle.
     * @param resource $target Plain output handle.
     * @param int $expectedPlainTotal Expected plaintext length from header (metadata).
     * @return int Bytes written.
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function decryptV2Segments($fh, $target, int $expectedPlainTotal): int
    {
        if ($this->encryptionKey === '') {
            throw new RuntimeException('file.encryption.key_missing');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength <= 0) {
            throw new RuntimeException('file.encryption.cipher_invalid');
        }

        $written = 0;
        while (!feof($fh)) {
            $lenIvPack = fread($fh, 2);
            if ($lenIvPack === false || $lenIvPack === '') {
                break;
            }
            if (strlen($lenIvPack) !== 2) {
                throw new RuntimeException('file.encryption.invalid_payload');
            }
            $ivLenArr = unpack('n', $lenIvPack);
            $ivLen = (int) ($ivLenArr[1] ?? 0);
            if ($ivLen < 1) {
                break;
            }
            $iv = fread($fh, $ivLen);
            if (!is_string($iv) || strlen($iv) !== $ivLen) {
                throw new RuntimeException('file.encryption.invalid_payload');
            }
            $cipherLenBin = fread($fh, 4);
            if ($cipherLenBin === false || strlen($cipherLenBin) !== 4) {
                throw new RuntimeException('file.encryption.invalid_payload');
            }
            $cipherLenArr = unpack('N', $cipherLenBin);
            $cipherLen = (int) ($cipherLenArr[1] ?? 0);
            $cipherText = fread($fh, $cipherLen);
            if (!is_string($cipherText) || strlen($cipherText) !== $cipherLen) {
                throw new RuntimeException('file.encryption.invalid_payload');
            }
            $plainChunk = openssl_decrypt($cipherText, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
            if (!is_string($plainChunk)) {
                throw new RuntimeException('file.encryption.failed');
            }
            fwrite($target, $plainChunk);
            $written += strlen($plainChunk);
        }

        if ($expectedPlainTotal > 0 && $written !== $expectedPlainTotal) {
            throw new RuntimeException('file.encryption.invalid_payload');
        }

        return $written;
    }

    /**
     * @brief Decrypt encrypted payload from storage path (legacy v1 base64 string file, or small v2 buffered).
     * @param string $storagePath Storage path.
     * @return string Full plaintext (avoid for very large v2 files; use streaming helpers).
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function decryptFromStorage(string $storagePath): string
    {
        if ($storagePath === '' || !is_readable($storagePath)) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        $size = filesize($storagePath);
        if ($size === false) {
            throw new RuntimeException('file.encryption.storage_unreadable');
        }

        if ($this->isV2StorageFormat($storagePath)) {
            $hdr = fopen($storagePath, 'rb');
            if ($hdr === false) {
                throw new RuntimeException('file.encryption.storage_unreadable');
            }
            fread($hdr, 4);
            fread($hdr, 1);
            fread($hdr, 3);
            $plainBin = fread($hdr, 8);
            fclose($hdr);
            if ($plainBin === false || strlen($plainBin) !== 8) {
                throw new RuntimeException('file.encryption.invalid_payload');
            }
            $plainExpectedArr = unpack('J', $plainBin);
            $plainExpected = is_array($plainExpectedArr) ? (int) ($plainExpectedArr[1] ?? 0) : 0;
            if ($plainExpected > self::DECRYPT_STRING_MAX_BYTES) {
                throw new RuntimeException('file.encryption.v2_use_streaming');
            }

            $tmp = fopen('php://temp/maxmemory:'.(string) self::DECRYPT_STRING_MAX_BYTES, 'rb+');
            if ($tmp === false) {
                throw new RuntimeException('file.encryption.failed');
            }
            try {
                $this->streamDecryptStorageToHandle($storagePath, $tmp);
                rewind($tmp);

                return stream_get_contents($tmp) ?: '';
            } finally {
                fclose($tmp);
            }
        }

        $encryptedContent = @file_get_contents($storagePath);
        if (!is_string($encryptedContent) || $encryptedContent === '') {
            throw new RuntimeException('file.encryption.storage_empty');
        }

        return $this->decrypt($encryptedContent);
    }

    /**
     * @brief Decrypt file content payload.
     * @param string $content Encrypted content.
     * @return string
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function decrypt(string $content): string
    {
        if ($this->encryptionKey === '') {
            throw new RuntimeException('file.encryption.key_missing');
        }

        $decoded = base64_decode($content, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('file.encryption.invalid_payload');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength <= 0 || strlen($decoded) <= $ivLength) {
            throw new RuntimeException('file.encryption.invalid_payload');
        }

        $iv = substr($decoded, 0, $ivLength);
        $cipherText = substr($decoded, $ivLength);
        $plainText = openssl_decrypt($cipherText, self::CIPHER, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if (!is_string($plainText)) {
            throw new RuntimeException('file.encryption.failed');
        }

        return $plainText;
    }
}
