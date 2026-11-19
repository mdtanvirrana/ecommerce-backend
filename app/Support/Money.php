<?php

namespace App\Support;

/**
 * Exact decimal money arithmetic using bcmath (never floating point).
 */
final class Money
{
    private const int SCALE = 2;

    public static function normalize(string|int $amount): string
    {
        return bcadd((string) $amount, '0', self::SCALE);
    }

    public static function add(string|int $a, string|int $b): string
    {
        return bcadd((string) $a, (string) $b, self::SCALE);
    }

    public static function sub(string|int $a, string|int $b): string
    {
        return bcsub((string) $a, (string) $b, self::SCALE);
    }

    public static function mul(string|int $a, string|int $b): string
    {
        return bcmul((string) $a, (string) $b, self::SCALE);
    }

    /**
     * Calculate exact percentage of an amount using 4 decimal precision then half-up rounding to 2 decimals.
     */
    public static function percentage(string|int $amount, string|int $percent): string
    {
        $raw = bcdiv(bcmul((string) $amount, (string) $percent, 4), '100', 4);

        return self::roundHalfUp($raw);
    }

    public static function roundHalfUp(string $value): string
    {
        if (str_starts_with($value, '-')) {
            return bcsub($value, '0.005', self::SCALE);
        }

        return bcadd($value, '0.005', self::SCALE);
    }

    public static function compare(string|int $a, string|int $b): int
    {
        return bccomp((string) $a, (string) $b, self::SCALE);
    }

    public static function min(string|int $a, string|int $b): string
    {
        return self::compare($a, $b) <= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function max(string|int $a, string|int $b): string
    {
        return self::compare($a, $b) >= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function toCents(string|int $amount): int
    {
        return (int) bcmul(self::normalize($amount), '100', 0);
    }

    public static function fromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', self::SCALE);
    }
}
