<?php

namespace App\Support;

final class BillingMoney
{
    public const MAX = 99999999999999;

    public static function parse(mixed $amount): int
    {
        if (! is_string($amount) || preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?$/D', $amount, $parts) !== 1) {
            throw new \InvalidArgumentException('Supply an exact NGN decimal with at most twelve whole and two fractional digits.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function format(int $kobo): string
    {
        if ($kobo < 0 || $kobo > self::MAX) {
            throw new \InvalidArgumentException('The amount exceeds its supported range.');
        }

        return intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function charge(string $unitPrice, string $quantity): int
    {
        $left = FixedPoint::parse($unitPrice);
        $right = FixedPoint::parse($quantity);
        if ($right <= 0) {
            throw new \InvalidArgumentException('A charge quantity must be positive.');
        }
        $a = strrev((string) $left);
        $b = strrev((string) $right);
        $digits = array_fill(0, 32, 0);
        for ($i = 0; $i < strlen($a); $i++) {
            for ($j = 0; $j < strlen($b); $j++) {
                $digits[$i + $j] += (int) $a[$i] * (int) $b[$j];
            }
        }
        for ($i = 0; $i < count($digits) - 1; $i++) {
            $digits[$i + 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }
        $product = str_pad(ltrim(implode('', array_reverse($digits)), '0'), 11, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($product, 0, -10), '0');
        if (strlen($whole) > 14) {
            throw new \InvalidArgumentException('The charge exceeds its supported range.');
        }
        $result = (int) $whole + ((int) substr($product, -10, 1) >= 5 ? 1 : 0);
        if ($result > self::MAX) {
            throw new \InvalidArgumentException('The charge exceeds its supported range.');
        }

        return $result;
    }
}
