<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for incremental v2 decrypt continuation.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class FileEncryptionServiceContinueTest extends TestCase
{
    private const ENCRYPTION_KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    /**
     * @brief Build a multi-segment encrypted fixture.
     * @return array{plain: string, enc: string, payload: string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function buildFixture(): array
    {
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);
        $plain = tempnam(sys_get_temp_dir(), 'v2c_plain_');
        $enc = tempnam(sys_get_temp_dir(), 'v2c_enc_');
        self::assertIsString($plain);
        self::assertIsString($enc);

        $payload = str_repeat('A', 4194304).str_repeat('B', 2048);
        file_put_contents($plain, $payload);
        $svc->encryptPlainFileToV2Storage($plain, $enc);

        return ['plain' => $plain, 'enc' => $enc, 'payload' => $payload];
    }

    /**
     * @brief Continued decrypt from stored cipher offsets reproduces full plaintext.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testContinueDecryptMatchesFullPlaintext(): void
    {
        $fixture = $this->buildFixture();
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);
        $outPath = tempnam(sys_get_temp_dir(), 'v2c_out_');
        self::assertIsString($outPath);
        @unlink($outPath);

        try {
            $offset = FileEncryptionService::V2_CIPHER_BODY_START_OFFSET;
            $totalWritten = 0;
            while ($totalWritten < strlen($fixture['payload'])) {
                $out = fopen($outPath, $totalWritten > 0 ? 'ab' : 'wb');
                self::assertIsResource($out);
                $result = $svc->streamDecryptStorageContinueToHandle($fixture['enc'], $offset, 1024 * 1024, $out);
                fclose($out);
                $totalWritten += (int) ($result['plainWritten'] ?? 0);
                $offset = (int) ($result['nextCipherOffset'] ?? 0);
                if ((int) ($result['plainWritten'] ?? 0) < 1) {
                    break;
                }
            }

            self::assertSame($fixture['payload'], file_get_contents($outPath));
        } finally {
            @unlink($fixture['plain']);
            @unlink($fixture['enc']);
            @unlink($outPath);
        }
    }
}
