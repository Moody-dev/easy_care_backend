<?php

namespace App\Support;

use InvalidArgumentException;

final class FixedPoint
{
    public const SCALE = 1000000;

    public const MAX = 999999999999999;

    public static function parse(mixed $value, bool $signed = false): int
    {
        if (! is_string($value) || ! preg_match('/\A(-?)(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,6}))?\z/D', $value, $parts)
            || ($parts[1] === '-' && ! $signed)) {
            throw new InvalidArgumentException('Use an exact decimal with at most nine whole and six fractional digits.');
        }
        $result = (int) $parts[2] * self::SCALE + (int) str_pad($parts[3] ?? '', 6, '0');

        return $parts[1] === '-' ? -$result : $result;
    }

    public static function format(mixed $micros): string
    {
        if (! is_int($micros) || $micros < -self::MAX || $micros > self::MAX) {
            throw new InvalidArgumentException('The fixed-point amount exceeds its storage limit.');
        }
        $absolute = abs($micros);

        return ($micros < 0 ? '-' : '').intdiv($absolute, self::SCALE).'.'.str_pad((string) ($absolute % self::SCALE), 6, '0', STR_PAD_LEFT);
    }

    public static function convert(mixed $packs, mixed $baseUnitsPerPack): string
    {
        $left = self::parse($packs);
        $right = self::parse($baseUnitsPerPack);
        if ($left <= 0 || $right <= 0) {
            throw new InvalidArgumentException('Pack count and conversion must be positive.');
        }
        $digits = array_fill(0, 30, 0);
        $a = strrev((string) $left);
        $b = strrev((string) $right);
        for ($i = 0; $i < strlen($a); $i++) {
            for ($j = 0; $j < strlen($b); $j++) {
                $digits[$i + $j] += (int) $a[$i] * (int) $b[$j];
            }
        }
        for ($i = 0; $i < count($digits) - 1; $i++) {
            $digits[$i + 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }
        $product = ltrim(implode('', array_reverse($digits)), '0');
        $product = str_pad($product, 7, '0', STR_PAD_LEFT);
        if (substr($product, -6) !== '000000') {
            throw new InvalidArgumentException('Conversion would require rounding below the base-unit precision.');
        }
        $result = ltrim(substr($product, 0, -6), '0');
        if (strlen($result) > 15 || ($result !== '' && (int) $result > self::MAX)) {
            throw new InvalidArgumentException('Converted quantity exceeds its storage limit.');
        }

        return self::format((int) $result);
    }
}
