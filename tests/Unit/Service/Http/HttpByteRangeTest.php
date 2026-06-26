<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Http;

use App\Service\Http\HttpByteRange;
use App\Service\Http\InvalidByteRangeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Unit tests for HTTP byte range parsing.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class HttpByteRangeTest extends TestCase
{
    /**
     * @brief Missing Range header yields null.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testTryFromRequestReturnsNullWhenHeaderAbsent(): void
    {
        $request = Request::create('/files/preview/1', 'GET');

        self::assertNull(HttpByteRange::tryFromRequest($request, 5000));
    }

    /**
     * @brief Closed byte range is parsed with correct length.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testParseClosedRange(): void
    {
        $range = HttpByteRange::parseHeader('bytes=0-1023', 5000);

        self::assertSame(0, $range->getStart());
        self::assertSame(1023, $range->getEnd());
        self::assertSame(1024, $range->getLength());
        self::assertSame('bytes 0-1023/5000', $range->contentRangeHeader());
    }

    /**
     * @brief Open-ended range runs through end of resource.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testParseOpenEndedRange(): void
    {
        $range = HttpByteRange::parseHeader('bytes=1024-', 5000);

        self::assertSame(1024, $range->getStart());
        self::assertSame(4999, $range->getEnd());
        self::assertSame(3976, $range->getLength());
    }

    /**
     * @brief Suffix range selects trailing bytes.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testParseSuffixRange(): void
    {
        $range = HttpByteRange::parseHeader('bytes=-500', 5000);

        self::assertSame(4500, $range->getStart());
        self::assertSame(4999, $range->getEnd());
        self::assertSame(500, $range->getLength());
    }

    /**
     * @brief Multipart ranges are rejected.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testMultipartRangeIsRejected(): void
    {
        $this->expectException(InvalidByteRangeException::class);

        HttpByteRange::parseHeader('bytes=0-1,2-3', 5000);
    }

    /**
     * @brief Out-of-bounds start offset is rejected.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function testUnsatisfiableRangeIsRejected(): void
    {
        $this->expectException(InvalidByteRangeException::class);

        HttpByteRange::parseHeader('bytes=5000-6000', 5000);
    }
}
