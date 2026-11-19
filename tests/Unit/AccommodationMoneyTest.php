<?php

namespace Tests\Unit;

use App\Support\AccommodationMoney;
use App\Support\BillingMoney;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AccommodationMoneyTest extends TestCase
{
    public function test_shares_use_exact_integer_arithmetic_and_half_up_rounding(): void
    {
        $day = AccommodationMoney::DAY_MICROS;
        $this->assertSame(500000, AccommodationMoney::portion(1000000, intdiv($day, 2)));
        $this->assertSame(1, AccommodationMoney::portion(1, intdiv($day, 2)));
        $this->assertSame(0, AccommodationMoney::portion(1, intdiv($day, 2) - 1));
        $this->assertSame(BillingMoney::MAX, AccommodationMoney::portion(BillingMoney::MAX, $day));
        $this->assertSame(0, AccommodationMoney::portion(0, PHP_INT_MAX));
    }

    public function test_an_out_of_range_product_fails_without_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccommodationMoney::portion(BillingMoney::MAX, AccommodationMoney::DAY_MICROS * 2);
    }
}
