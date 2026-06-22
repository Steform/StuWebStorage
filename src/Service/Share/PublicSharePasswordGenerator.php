<?php

declare(strict_types=1);

namespace App\Service\Share;

/**
 * @brief Generates 18-character passwords with upper, lower, digit, and special class membership.
 * @author Stephane H.
 * @date 2026-05-04
 */
final class PublicSharePasswordGenerator
{
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';

    private const DIGIT = '23456789';

    /** URL-friendly specials (encoded safely when passed as query value). */
    private const SPECIAL = '!@#$%&*-_=+';

    /**
     * @brief Build a new random password satisfying character-class rules.
     * @param callable(int,int): int|null $randomRange Optional RNG for tests (random_int signature).
     * @return string Plain password (18 chars).
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function generate(?callable $randomRange = null): string
    {
        $rng = $randomRange ?? static fn (int $min, int $max): int => random_int($min, $max);

        $chars = [
            self::UPPER[$rng(0, strlen(self::UPPER) - 1)],
            self::LOWER[$rng(0, strlen(self::LOWER) - 1)],
            self::DIGIT[$rng(0, strlen(self::DIGIT) - 1)],
            self::SPECIAL[$rng(0, strlen(self::SPECIAL) - 1)],
        ];

        $alphabet = self::UPPER.self::LOWER.self::DIGIT.self::SPECIAL;
        $alphabetLen = strlen($alphabet);
        for ($i = 4; $i < 18; ++$i) {
            $chars[] = $alphabet[$rng(0, $alphabetLen - 1)];
        }

        for ($i = 17; $i > 0; --$i) {
            $j = $rng(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
