<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Format\BinaryByteFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * @brief Twig extension exposing the files_size_format filter that turns a
 *        byte count into a human readable binary representation
 *        (o, Ko, Mo, Go, To, Po) with two decimal places.
 * @author Stephane H.
 * @date 2026-04-27
 */
final class FilesSizeExtension extends AbstractExtension
{
    public function __construct(
        private readonly BinaryByteFormatter $binaryByteFormatter,
    ) {
    }

    /**
     * @brief Declare the Twig filters exposed by this extension.
     * @return TwigFilter[] List of registered filters.
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('files_size_format', [$this, 'format']),
        ];
    }

    /**
     * @brief Format a byte size using binary units (1024 based).
     *
     * @param int|float|null $bytes Byte count to format. Null and negative
     *                              values are coerced to zero.
     * @return string Human readable representation, e.g. "0 o",
     *                "999 o", "1.50 Ko", "2.34 Go".
     * @author Stephane H.
     * @date 2026-04-27
     */
    public function format(int|float|null $bytes): string
    {
        return $this->binaryByteFormatter->format($bytes);
    }
}
