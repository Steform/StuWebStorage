<?php

declare(strict_types=1);

namespace App\Service\Format;

/**
 * @brief Human-readable binary byte counts for UI (aligned with `files_size_format` Twig filter).
 *        Uses French-style unit symbols (o, Ko, Mo, Go, To, Po) for 1024-based steps, not decimal SI.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class BinaryByteFormatter
{
    /**
     * @brief Format a byte size using binary units (1024 based).
     *
     * The highest applicable unit is selected so the displayed value is
     * always >= 1 (or exactly 0 for zero bytes). Raw octets are rendered as an
     * integer; every other unit is formatted with two decimal places using
     * a dot as decimal separator and no thousands separator.
     *
     * @param int|float|null $bytes Byte count; null and negative values coerced to zero.
     * @return string Human readable representation, e.g. "0 o", "999 o", "1.50 Ko", "2.34 Go".
     * @author Stephane H.
     * @date 2026-05-03
     */
    public function format(int|float|null $bytes): string
    {
        $value = (float) max(0, (int) ($bytes ?? 0));
        $units = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];
        $index = 0;
        $maxIndex = count($units) - 1;

        while ($value >= 1024.0 && $index < $maxIndex) {
            $value /= 1024.0;
            $index++;
        }

        if ($index === 0) {
            return ((int) $value).' '.$units[0];
        }

        return number_format($value, 2, '.', '').' '.$units[$index];
    }
}
