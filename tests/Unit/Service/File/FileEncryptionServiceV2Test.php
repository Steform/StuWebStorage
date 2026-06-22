<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Round-trip tests for v2 streaming storage format.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class FileEncryptionServiceV2Test extends TestCase
{
    /**
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function testEncryptV2FromPlainFileAndStreamDecryptRoundTrip(): void
    {
        $key = str_repeat('k', 32);
        $svc = new FileEncryptionService($key);

        $plain = tempnam(sys_get_temp_dir(), 'v2p');
        $enc = tempnam(sys_get_temp_dir(), 'v2e');
        $out = tempnam(sys_get_temp_dir(), 'v2o');
        self::assertIsString($plain);
        self::assertIsString($enc);
        self::assertIsString($out);

        try {
            $payload = "hello-v2-".random_int(0, 1000000);
            file_put_contents($plain, $payload);

            $n = $svc->encryptPlainFileToV2Storage($plain, $enc);
            self::assertSame(strlen($payload), $n);
            self::assertTrue($svc->isV2StorageFormat($enc));

            $h = fopen($out, 'wb');
            self::assertIsResource($h);
            $written = $svc->streamDecryptStorageToHandle($enc, $h);
            fclose($h);

            self::assertSame(strlen($payload), $written);
            self::assertSame($payload, (string) file_get_contents($out));
        } finally {
            @unlink($plain);
            @unlink($enc);
            @unlink($out);
        }
    }
}
