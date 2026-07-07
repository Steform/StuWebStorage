<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileEncryptionService;
use App\Service\File\V2SegmentIndex;
use App\Service\File\V2SegmentIndexService;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for v2 segment index sidecars.
 * @date 2026-07-07
 * @author Stephane H.
 */
final class V2SegmentIndexServiceTest extends TestCase
{
    private const ENCRYPTION_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @brief Index is written during encryption and reloads correctly.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testIndexWrittenOnEncryptAndReloaded(): void
    {
        $projectDir = sys_get_temp_dir().'/idx-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0775, true);
        $plainPath = $projectDir.'/plain.bin';
        $storagePath = $projectDir.'/storage.cvf2';
        file_put_contents($plainPath, str_repeat('Z', 9000000));

        $encryption = new FileEncryptionService(self::ENCRYPTION_KEY);
        $encryption->encryptPlainFileToV2Storage($plainPath, $storagePath);

        $service = new V2SegmentIndexService($encryption);
        $index = $service->loadIndex($storagePath);
        self::assertInstanceOf(V2SegmentIndex::class, $index);
        self::assertGreaterThan(1, count($index->cipherOffsets));
        self::assertSame(9000000, $index->plainTotal);
    }

    /**
     * @brief Indexed range decrypt matches scan-based range decrypt.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function testIndexedRangeMatchesScanRange(): void
    {
        $projectDir = sys_get_temp_dir().'/idx-range-'.bin2hex(random_bytes(4));
        mkdir($projectDir, 0775, true);
        $plain = str_repeat('Q', 9000000);
        $plainPath = $projectDir.'/plain.bin';
        $storagePath = $projectDir.'/storage.cvf2';
        file_put_contents($plainPath, $plain);

        $encryption = new FileEncryptionService(self::ENCRYPTION_KEY);
        $encryption->encryptPlainFileToV2Storage($plainPath, $storagePath);
        $index = (new V2SegmentIndexService($encryption))->loadIndex($storagePath);
        self::assertNotNull($index);

        $start = 6000000;
        $length = 4096;
        $scanOut = fopen('php://memory', 'wb+');
        $indexOut = fopen('php://memory', 'wb+');
        self::assertIsResource($scanOut);
        self::assertIsResource($indexOut);

        $encryption->streamDecryptStorageRangeToHandle($storagePath, $start, $length, $scanOut);
        $encryption->streamDecryptStorageRangeFromIndex($storagePath, $index, $start, $length, $indexOut);

        rewind($scanOut);
        rewind($indexOut);
        self::assertSame(stream_get_contents($scanOut), stream_get_contents($indexOut));
    }
}
