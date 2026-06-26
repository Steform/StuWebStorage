<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileEncryptionService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for v2 partial plaintext range decryption.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class FileEncryptionServiceV2RangeTest extends TestCase
{
    private const ENCRYPTION_KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    /**
     * @brief Build a multi-segment v2 encrypted fixture and return paths.
     * @return array{plain: string, enc: string, payload: string}
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function buildMultiSegmentFixture(): array
    {
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);
        $plain = tempnam(sys_get_temp_dir(), 'v2r_plain_');
        $enc = tempnam(sys_get_temp_dir(), 'v2r_enc_');
        self::assertIsString($plain);
        self::assertIsString($enc);

        $segmentSize = 4194304;
        $payload = str_repeat('A', $segmentSize)
            .str_repeat('B', $segmentSize)
            .str_repeat('C', 2048);
        file_put_contents($plain, $payload);
        $svc->encryptPlainFileToV2Storage($plain, $enc);

        return ['plain' => $plain, 'enc' => $enc, 'payload' => $payload];
    }

    /**
     * @brief Read a plaintext range from encrypted storage into a string.
     * @param FileEncryptionService $svc Encryption service.
     * @param string $encPath Encrypted storage path.
     * @param int $start Inclusive start offset.
     * @param int $length Number of bytes.
     * @return string
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function readRangeToString(FileEncryptionService $svc, string $encPath, int $start, int $length): string
    {
        $tmp = fopen('php://temp', 'rb+');
        self::assertIsResource($tmp);
        $svc->streamDecryptStorageRangeToHandle($encPath, $start, $length, $tmp);
        rewind($tmp);
        $out = stream_get_contents($tmp);
        fclose($tmp);

        return is_string($out) ? $out : '';
    }

    /**
     * @brief First kilobyte of a multi-segment file matches plaintext prefix.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testRangeAtStartOfMultiSegmentFile(): void
    {
        $fixture = $this->buildMultiSegmentFixture();
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);

        try {
            $slice = $this->readRangeToString($svc, $fixture['enc'], 0, 1024);
            self::assertSame(substr($fixture['payload'], 0, 1024), $slice);
        } finally {
            @unlink($fixture['plain']);
            @unlink($fixture['enc']);
        }
    }

    /**
     * @brief Range in the middle of one segment is extracted correctly.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testRangeInsideSingleSegment(): void
    {
        $fixture = $this->buildMultiSegmentFixture();
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);

        try {
            $slice = $this->readRangeToString($svc, $fixture['enc'], 5000, 256);
            self::assertSame(substr($fixture['payload'], 5000, 256), $slice);
        } finally {
            @unlink($fixture['plain']);
            @unlink($fixture['enc']);
        }
    }

    /**
     * @brief Range spanning two segments returns the expected bytes.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testRangeAcrossSegmentBoundary(): void
    {
        $fixture = $this->buildMultiSegmentFixture();
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);
        $start = 4194304 - 10;
        $length = 20;

        try {
            $slice = $this->readRangeToString($svc, $fixture['enc'], $start, $length);
            self::assertSame(substr($fixture['payload'], $start, $length), $slice);
        } finally {
            @unlink($fixture['plain']);
            @unlink($fixture['enc']);
        }
    }

    /**
     * @brief Suffix-style trailing range matches plaintext tail.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testRangeAtEndOfFile(): void
    {
        $fixture = $this->buildMultiSegmentFixture();
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);
        $length = 500;
        $start = strlen($fixture['payload']) - $length;

        try {
            $slice = $this->readRangeToString($svc, $fixture['enc'], $start, $length);
            self::assertSame(substr($fixture['payload'], $start, $length), $slice);
        } finally {
            @unlink($fixture['plain']);
            @unlink($fixture['enc']);
        }
    }

    /**
     * @brief Concatenated ranges covering the full file equal a full decrypt.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testConcatenatedRangesMatchFullDecrypt(): void
    {
        $fixture = $this->buildMultiSegmentFixture();
        $svc = new FileEncryptionService(self::ENCRYPTION_KEY);

        try {
            $partA = $this->readRangeToString($svc, $fixture['enc'], 0, 1024);
            $partB = $this->readRangeToString($svc, $fixture['enc'], 1024, strlen($fixture['payload']) - 1024);
            self::assertSame($fixture['payload'], $partA.$partB);
        } finally {
            @unlink($fixture['plain']);
            @unlink($fixture['enc']);
        }
    }
}
