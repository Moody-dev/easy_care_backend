<?php

namespace App\Support;

use InvalidArgumentException;

final class AccommodationMoney
{
    public const DAY_MICROS = 86400000000;

    public static function portion(int $dailyKobo, int $microseconds): int
    {
        if ($dailyKobo < 0 || $dailyKobo > BillingMoney::MAX || $microseconds < 0) {
            throw new InvalidArgumentException('Accommodation amounts require nonnegative exact integers.');
        }
        $a = strrev((string) $dailyKobo);
        $b = strrev((string) $microseconds);
        $digits = array_fill(0, strlen($a) + strlen($b) + 1, 0);
        for ($i = 0; $i < strlen($a); $i++) {
            for ($j = 0; $j < strlen($b); $j++) {
                $digits[$i + $j] += (int) $a[$i] * (int) $b[$j];
            }
        }
        for ($i = 0; $i < count($digits) - 1; $i++) {
            $digits[$i + 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }
        $remainder = 0;
        $quotient = '';
        foreach (array_reverse($digits) as $digit) {
            $value = $remainder * 10 + $digit;
            $quotient .= (string) intdiv($value, self::DAY_MICROS);
            $remainder = $value % self::DAY_MICROS;
        }
        $quotient = ltrim($quotient, '0') ?: '0';
        if (strlen($quotient) > 14) {
            throw new InvalidArgumentException('Accommodation charge exceeds the supported monetary range.');
        }
        $result = (int) $quotient + ($remainder * 2 >= self::DAY_MICROS ? 1 : 0);
        if ($result > BillingMoney::MAX) {
            throw new InvalidArgumentException('Accommodation charge exceeds the supported monetary range.');
        }

        return $result;
    }
}
