<?php

declare(strict_types=1);

namespace App\Service\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Parsed single HTTP byte range for partial content responses.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class HttpByteRange
{
    /**
     * @brief Construct a validated byte range.
     * @param int $start Inclusive start offset.
     * @param int $end Inclusive end offset.
     * @param int $totalSize Total resource size in bytes.
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function __construct(
        private readonly int $start,
        private readonly int $end,
        private readonly int $totalSize,
    ) {
    }

    /**
     * @brief Parse Range header from request or return null when absent.
     * @param Request $request Incoming HTTP request.
     * @param int $totalSize Total plaintext byte size.
     * @return self|null Parsed range, or null when no Range header is present.
     * @throws InvalidByteRangeException When Range is present but invalid.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public static function tryFromRequest(Request $request, int $totalSize): ?self
    {
        $header = $request->headers->get('Range');
        if ($header === null || $header === '') {
            return null;
        }

        return self::parseHeader($header, $totalSize);
    }

    /**
     * @brief Parse a Range header value.
     * @param string $header Raw Range header value.
     * @param int $totalSize Total plaintext byte size.
     * @return self Parsed range.
     * @throws InvalidByteRangeException When the header is invalid or unsatisfiable.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public static function parseHeader(string $header, int $totalSize): self
    {
        if ($totalSize < 0) {
            throw new InvalidByteRangeException('http.range.invalid_total_size');
        }

        if ($totalSize === 0) {
            throw new InvalidByteRangeException('http.range.empty_resource');
        }

        if (!preg_match('/^\s*bytes\s*=\s*(.+)\s*$/i', $header, $matches)) {
            throw new InvalidByteRangeException('http.range.invalid_syntax');
        }

        $spec = trim($matches[1]);
        if ($spec === '' || str_contains($spec, ',')) {
            throw new InvalidByteRangeException('http.range.multipart_not_supported');
        }

        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $parts)) {
            throw new InvalidByteRangeException('http.range.invalid_syntax');
        }

        $rawStart = $parts[1];
        $rawEnd = $parts[2];

        if ($rawStart === '' && $rawEnd === '') {
            throw new InvalidByteRangeException('http.range.invalid_syntax');
        }

        if ($rawStart === '') {
            $suffixLength = (int) $rawEnd;
            if ($suffixLength < 1) {
                throw new InvalidByteRangeException('http.range.invalid_suffix');
            }
            $start = max(0, $totalSize - $suffixLength);
            $end = $totalSize - 1;
        } elseif ($rawEnd === '') {
            $start = (int) $rawStart;
            $end = $totalSize - 1;
        } else {
            $start = (int) $rawStart;
            $end = (int) $rawEnd;
        }

        if ($start < 0 || $end < $start || $start >= $totalSize) {
            throw new InvalidByteRangeException('http.range.unsatisfiable');
        }

        $end = min($end, $totalSize - 1);

        return new self($start, $end, $totalSize);
    }

    /**
     * @brief Inclusive range start offset.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getStart(): int
    {
        return $this->start;
    }

    /**
     * @brief Inclusive range end offset.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getEnd(): int
    {
        return $this->end;
    }

    /**
     * @brief Number of bytes in the range.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getLength(): int
    {
        return $this->end - $this->start + 1;
    }

    /**
     * @brief Total resource size used for validation.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getTotalSize(): int
    {
        return $this->totalSize;
    }

    /**
     * @brief Build Content-Range response header value.
     * @return string
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function contentRangeHeader(): string
    {
        return sprintf('bytes %d-%d/%d', $this->start, $this->end, $this->totalSize);
    }
}
