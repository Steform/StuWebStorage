<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for resumable CVF2 encrypt ticks (EPIC-ENC).
 * @date 2026-11-09
 * @author Stephane H.
 */
final class FileEncryptionServiceTickTest extends TestCase
{
    /**
     * @brief Encrypt a multi-megabyte file across several ticks and verify decrypt round-trip.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testEncryptTickCompletesAcrossMultipleBudgets(): void
    {
        $key = str_repeat('k', 32);
        $svc = new FileEncryptionService($key);
        $dir = sys_get_temp_dir().'/stuweb-enc-tick-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $plain = $dir.'/plain.bin';
        $cipher = $dir.'/cipher.dat';
        $payload = random_bytes(FileEncryptionService::PLAIN_CHUNK_BYTES + 1024);
        file_put_contents($plain, $payload);

        $state = null;
        $complete = false;
        $fingerprints = null;
        $guard = 0;
        do {
            $tick = $svc->encryptPlainFileToV2StorageTick(
                $plain,
                $cipher,
                $state,
                FileEncryptionService::PLAIN_CHUNK_BYTES,
                null,
            );
            $state = $tick['state'];
            $complete = (bool) $tick['complete'];
            if ($complete) {
                $fingerprints = $tick['fingerprints'];
            }
            ++$guard;
        } while (!$complete && $guard < 20);

        self::assertTrue($complete);
        self::assertNotNull($fingerprints);
        self::assertSame(strlen($payload), (int) $fingerprints['plain_total']);
        self::assertSame(hash('sha256', $payload), $fingerprints['content_sha256']);

        $out = $dir.'/out.bin';
        $fh = fopen($out, 'wb');
        self::assertNotFalse($fh);
        $svc->streamDecryptStorageToHandle($cipher, $fh);
        fclose($fh);
        self::assertSame(hash_file('sha256', $plain), hash_file('sha256', $out));

        @unlink($plain);
        @unlink($cipher);
        @unlink($cipher.'.cvf2idx');
        @unlink($out);
        @rmdir($dir);
    }

    /**
     * @brief Cancel probe aborts a tick with encryption.cancelled.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function testEncryptTickHonoursCancelProbe(): void
    {
        $key = str_repeat('k', 32);
        $svc = new FileEncryptionService($key);
        $dir = sys_get_temp_dir().'/stuweb-enc-cancel-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $plain = $dir.'/plain.bin';
        $cipher = $dir.'/cipher.dat';
        file_put_contents($plain, random_bytes(FileEncryptionService::PLAIN_CHUNK_BYTES * 3));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('file.encryption.cancelled');
        try {
            $svc->encryptPlainFileToV2StorageTick(
                $plain,
                $cipher,
                null,
                FileEncryptionService::PLAIN_CHUNK_BYTES * 3,
                static fn (): bool => true,
            );
        } finally {
            @unlink($plain);
            @unlink($cipher);
            @unlink($cipher.'.cvf2idx');
            @rmdir($dir);
        }
    }
}
