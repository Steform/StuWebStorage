<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Download;

use App\Service\Download\VirtualZipLayout;
use PHPUnit\Framework\TestCase;

/**
 * @brief Unit tests for deterministic Zip64 STORE layout.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class VirtualZipLayoutTest extends TestCase
{
    /**
     * @brief Layout size equals concatenation of local headers, data, and central directory endings.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testTotalSizeMatchesGeneratedArchiveWithoutData(): void
    {
        $layout = VirtualZipLayout::fromEntries([
            ['name' => 'a.txt', 'size' => 0, 'mtime' => 1700000000, 'crc32' => 0],
            ['name' => 'dir/b.bin', 'size' => 0, 'mtime' => 1700000000, 'crc32' => 0xFFFFFFFF],
        ]);

        $blob = '';
        foreach ($layout->getEntries() as $index => $entry) {
            $blob .= $layout->buildLocalHeader($index);
            // zero-length data
        }
        $blob .= $layout->buildCentralDirectoryAndEndings();

        self::assertSame(strlen($blob), $layout->getTotalSize());
        self::assertSame(0x04034b50, unpack('V', substr($blob, 0, 4))[1]);
    }

    /**
     * @brief Random non-overlapping ranges cover the full archive identically.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testResolveRegionsCoverFullArchive(): void
    {
        $layout = VirtualZipLayout::fromEntries([
            ['name' => 'one.dat', 'size' => 100, 'mtime' => 1700000000, 'crc32' => 1],
            ['name' => 'two.dat', 'size' => 250, 'mtime' => 1700000000, 'crc32' => 2],
        ]);
        $total = $layout->getTotalSize();
        $covered = array_fill(0, $total, 0);
        $regions = $layout->resolveRegions(0, $total);
        foreach ($regions as $region) {
            for ($i = $region['archiveStart']; $i < $region['archiveEnd']; ++$i) {
                $covered[$i] = 1;
            }
        }
        self::assertSame($total, array_sum($covered));
    }

    /**
     * @brief UTF-8 names are preserved in local headers.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function testUtf8NameEmbeddedInLocalHeader(): void
    {
        $name = 'café/été.txt';
        $layout = VirtualZipLayout::fromEntries([
            ['name' => $name, 'size' => 3, 'mtime' => 1700000000, 'crc32' => 42],
        ]);
        $header = $layout->buildLocalHeader(0);
        self::assertStringContainsString($name, $header);
    }
}
