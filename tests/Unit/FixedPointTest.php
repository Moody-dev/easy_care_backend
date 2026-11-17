<?php

namespace Tests\Unit;

use App\Support\FixedPoint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FixedPointTest extends TestCase
{
    public function test_quantities_and_money_round_trip_without_binary_floats(): void
    {
        $this->assertSame(100001, FixedPoint::parse('0.100001'));
        $this->assertSame('0.100001', FixedPoint::format(100001));
        $this->assertSame(FixedPoint::MAX, FixedPoint::parse('999999999.999999'));
        $this->assertSame('-0.000001', FixedPoint::format(FixedPoint::parse('-0.000001', true)));
        $this->assertSame('0.000000', FixedPoint::format(0));
        $this->assertSame('0.000000', FixedPoint::format(FixedPoint::parse('-0', true)));
    }

    #[DataProvider('invalidDecimals')]
    public function test_ambiguous_excess_precision_or_out_of_range_decimal_is_rejected(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        FixedPoint::parse($value);
    }

    public static function invalidDecimals(): array
    {
        return array_map(fn (mixed $value): array => [$value], ['', ' 1', '1 ', '1e3', '1,000', '01', '.5', '1.', '+1', '-1', '0.0000001', '1000000000', 'NaN', 1.2, 1, null, true]);
    }

    #[DataProvider('conversions')]
    public function test_exact_pack_conversion(string $packs, string $factor, string $expected): void
    {
        $this->assertSame($expected, FixedPoint::convert($packs, $factor));
    }

    public static function conversions(): array
    {
        return [['2.5', '10', '25.000000'], ['0.1', '0.1', '0.010000'], ['1', '999999999.999999', '999999999.999999'],
            ['999999999', '0.000001', '999.999999']];
    }

    #[DataProvider('invalidConversions')]
    public function test_conversion_rejects_rounding_overflow_or_nonpositive_values(string $packs, string $factor): void
    {
        $this->expectException(InvalidArgumentException::class);
        FixedPoint::convert($packs, $factor);
    }

    public static function invalidConversions(): array
    {
        return [['0.000001', '0.000001'], ['999999999.999999', '0.000001'], ['999999999.999999', '999999999.999999'], ['2', '999999999'], ['0', '1'], ['1', '0']];
    }

    public function test_format_rejects_amounts_outside_storage_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FixedPoint::format(PHP_INT_MIN);
    }
}
