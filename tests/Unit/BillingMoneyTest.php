<?php

namespace Tests\Unit;

use App\Support\BillingMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingMoneyTest extends TestCase
{
    #[DataProvider('charges')]
    public function test_charge_rounds_once_half_up_without_float_or_intermediate_rounding(string $price, string $quantity, int $kobo): void
    {
        $this->assertSame($kobo, BillingMoney::charge($price, $quantity));
    }

    public static function charges(): array
    {
        return [['1.005', '1', 101], ['0.335', '3', 101], ['0.333333', '3', 100],
            ['0.004999', '1', 0], ['0.005', '1', 1], ['1.234567', '0.012345', 2],
            ['999999999.999999', '1', 100000000000], ['0', '999999999.999999', 0]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_money_rejects_imprecise_or_oversized_values(mixed $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillingMoney::parse($amount);
    }

    public static function invalidAmounts(): array
    {
        return [[1.2], ['1.005'], ['-1'], ['1e3'], ['1,000'], ['1000000000000'], [' 1 ']];
    }

    public function test_exact_kobo_round_trip_and_large_product_overflow(): void
    {
        $this->assertSame(99999999999999, BillingMoney::parse('999999999999.99'));
        $this->assertSame('999999999999.99', BillingMoney::format(99999999999999));
        $this->expectException(InvalidArgumentException::class);
        BillingMoney::charge('999999999.999999', '999999999.999999');
    }
}
