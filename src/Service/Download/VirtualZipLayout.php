<?php

declare(strict_types=1);

namespace App\Service\Download;

/**
 * @brief Deterministic Zip64 STORE layout with O(log n) offset resolution for Range delivery.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class VirtualZipLayout
{
    public const LAYOUT_VERSION = 1;

    private const LOCAL_FIXED = 30;

    private const LOCAL_ZIP64_EXTRA = 20;

    private const CENTRAL_FIXED = 46;

    private const CENTRAL_ZIP64_EXTRA = 28;

    private const ZIP64_EOCD = 56;

    private const ZIP64_LOCATOR = 20;

    private const EOCD = 22;

    /** @var list<array{name: string, size: int, mtime: int, crc32: int, localOffset: int, dataOffset: int, localSize: int}> */
    private array $entries = [];

    private int $centralDirectoryOffset = 0;

    private int $centralDirectorySize = 0;

    private int $totalSize = 0;

    /**
     * @brief Build layout from ordered entry descriptors.
     * @param list<array{name: string, size: int, mtime: int, crc32: int}> $entries Entry list.
     * @return self
     * @date 2026-11-02
     * @author Stephane H.
     */
    public static function fromEntries(array $entries): self
    {
        $layout = new self();
        $offset = 0;
        foreach ($entries as $entry) {
            $name = (string) $entry['name'];
            $nameLen = strlen($name);
            $size = (int) $entry['size'];
            $localHeaderSize = self::LOCAL_FIXED + $nameLen + self::LOCAL_ZIP64_EXTRA;
            $layout->entries[] = [
                'name' => $name,
                'size' => $size,
                'mtime' => (int) $entry['mtime'],
                'crc32' => ((int) $entry['crc32']) & 0xFFFFFFFF,
                'localOffset' => $offset,
                'dataOffset' => $offset + $localHeaderSize,
                'localSize' => $localHeaderSize,
            ];
            $offset += $localHeaderSize + $size;
        }

        $layout->centralDirectoryOffset = $offset;
        $centralSize = 0;
        foreach ($layout->entries as $entry) {
            $centralSize += self::CENTRAL_FIXED + strlen($entry['name']) + self::CENTRAL_ZIP64_EXTRA;
        }
        $layout->centralDirectorySize = $centralSize;
        $layout->totalSize = $offset + $centralSize + self::ZIP64_EOCD + self::ZIP64_LOCATOR + self::EOCD;

        return $layout;
    }

    /**
     * @brief Total archive byte size.
     * @return int
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    /**
     * @brief Entry count.
     * @return int
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function getEntryCount(): int
    {
        return count($this->entries);
    }

    /**
     * @brief Absolute offset where the central directory begins.
     * @return int
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function getCentralDirectoryOffset(): int
    {
        return $this->centralDirectoryOffset;
    }

    /**
     * @brief Ordered entries with layout offsets.
     * @return list<array{name: string, size: int, mtime: int, crc32: int, localOffset: int, dataOffset: int, localSize: int}>
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @brief Resolve which archive regions overlap [start, start+length).
     * @param int $start Inclusive byte offset.
     * @param int $length Byte length.
     * @return list<array{type: string, entryIndex?: int, archiveStart: int, archiveEnd: int, sourceOffset: int}>
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function resolveRegions(int $start, int $length): array
    {
        if ($length < 1 || $start >= $this->totalSize) {
            return [];
        }
        $end = min($this->totalSize, $start + $length);
        $regions = [];

        foreach ($this->entries as $index => $entry) {
            $localStart = $entry['localOffset'];
            $localEnd = $entry['dataOffset'];
            $dataStart = $entry['dataOffset'];
            $dataEnd = $entry['dataOffset'] + $entry['size'];

            $this->pushOverlap($regions, 'local_header', $index, $localStart, $localEnd, $start, $end, 0);
            $this->pushOverlap($regions, 'data', $index, $dataStart, $dataEnd, $start, $end, 0);
        }

        $cdStart = $this->centralDirectoryOffset;
        $cdEnd = $cdStart + $this->centralDirectorySize;
        $this->pushOverlap($regions, 'central_directory', null, $cdStart, $cdEnd, $start, $end, 0);

        $z64Start = $cdEnd;
        $z64End = $z64Start + self::ZIP64_EOCD;
        $this->pushOverlap($regions, 'zip64_eocd', null, $z64Start, $z64End, $start, $end, 0);

        $locStart = $z64End;
        $locEnd = $locStart + self::ZIP64_LOCATOR;
        $this->pushOverlap($regions, 'zip64_locator', null, $locStart, $locEnd, $start, $end, 0);

        $eocdStart = $locEnd;
        $eocdEnd = $eocdStart + self::EOCD;
        $this->pushOverlap($regions, 'eocd', null, $eocdStart, $eocdEnd, $start, $end, 0);

        return $regions;
    }

    /**
     * @brief Build local file header bytes for one entry.
     * @param int $entryIndex Entry index.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function buildLocalHeader(int $entryIndex): string
    {
        $entry = $this->entries[$entryIndex];
        $dos = $this->unixToDosTime($entry['mtime']);
        $name = $entry['name'];
        $nameLen = strlen($name);

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            45,
            0x0800,
            0,
            $dos['time'],
            $dos['date'],
            $entry['crc32'],
            0xFFFFFFFF,
            0xFFFFFFFF,
            $nameLen,
            self::LOCAL_ZIP64_EXTRA,
        );
        // Zip64 extra: id(2) + size(2) + uncomp(8) + comp(8) = 20 bytes.
        $extra = pack('vvPP', 0x0001, 16, $entry['size'], $entry['size']);

        return $header.$name.$extra;
    }

    /**
     * @brief Build the full central directory + zip64 endings.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function buildCentralDirectoryAndEndings(): string
    {
        $out = '';
        foreach ($this->entries as $entry) {
            $dos = $this->unixToDosTime($entry['mtime']);
            $name = $entry['name'];
            $nameLen = strlen($name);
            $out .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                45,
                45,
                0x0800,
                0,
                $dos['time'],
                $dos['date'],
                $entry['crc32'],
                0xFFFFFFFF,
                0xFFFFFFFF,
                $nameLen,
                self::CENTRAL_ZIP64_EXTRA,
                0,
                0,
                0,
                0,
                0xFFFFFFFF,
            );
            // Zip64 extra: id(2) + size(2) + uncomp(8) + comp(8) + local offset(8) = 28 bytes.
            $extra = pack('vvPPP', 0x0001, 24, $entry['size'], $entry['size'], $entry['localOffset']);
            $out .= $name.$extra;
        }

        $entryCount = count($this->entries);
        $zip64EocdOffset = $this->centralDirectoryOffset + $this->centralDirectorySize;
        $out .= pack(
            'VPvvVVPPPP',
            0x06064b50,
            44,
            45,
            45,
            0,
            0,
            $entryCount,
            $entryCount,
            $this->centralDirectorySize,
            $this->centralDirectoryOffset,
        );
        $out .= pack('VVPV', 0x07064b50, 0, $zip64EocdOffset, 1);
        $out .= pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            0xFFFF,
            0xFFFF,
            0xFFFFFFFF,
            0xFFFFFFFF,
            0,
        );

        return $out;
    }

    /**
     * @brief Slice bytes from a generated header blob for a region overlap.
     * @param string $blob Full header blob for the region.
     * @param int $regionArchiveStart Absolute archive offset of blob[0].
     * @param int $archiveStart Requested overlap start.
     * @param int $archiveEnd Requested overlap end (exclusive).
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function sliceBlob(string $blob, int $regionArchiveStart, int $archiveStart, int $archiveEnd): string
    {
        $offsetInBlob = $archiveStart - $regionArchiveStart;
        $len = $archiveEnd - $archiveStart;
        if ($offsetInBlob < 0 || $len < 1) {
            return '';
        }

        return substr($blob, $offsetInBlob, $len) ?: '';
    }

    /**
     * @param list<array{type: string, entryIndex?: int, archiveStart: int, archiveEnd: int, sourceOffset: int}> $regions
     * @param string $type Region type.
     * @param int|null $entryIndex Entry index when applicable.
     * @param int $regionStart Absolute start.
     * @param int $regionEnd Absolute end exclusive.
     * @param int $reqStart Request start.
     * @param int $reqEnd Request end exclusive.
     * @param int $sourceBase Source offset base for data regions.
     * @return void
     */
    private function pushOverlap(
        array &$regions,
        string $type,
        ?int $entryIndex,
        int $regionStart,
        int $regionEnd,
        int $reqStart,
        int $reqEnd,
        int $sourceBase,
    ): void {
        $start = max($regionStart, $reqStart);
        $end = min($regionEnd, $reqEnd);
        if ($end <= $start) {
            return;
        }
        $item = [
            'type' => $type,
            'archiveStart' => $start,
            'archiveEnd' => $end,
            'sourceOffset' => $sourceBase + ($start - $regionStart),
        ];
        if ($entryIndex !== null) {
            $item['entryIndex'] = $entryIndex;
        }
        $regions[] = $item;
    }

    /**
     * @brief Convert unix timestamp to DOS date/time fields.
     * @param int $unix Unix timestamp.
     * @return array{time: int, date: int}
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function unixToDosTime(int $unix): array
    {
        if ($unix < 315532800) {
            $unix = 315532800;
        }
        $parts = getdate($unix);

        return [
            'time' => ($parts['hours'] << 11) | ($parts['minutes'] << 5) | ((int) floor($parts['seconds'] / 2)),
            'date' => ((($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday']),
        ];
    }
}
