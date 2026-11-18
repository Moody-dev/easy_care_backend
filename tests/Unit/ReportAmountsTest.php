<?php

namespace Tests\Unit;

use App\Support\ReportAmounts;
use PHPUnit\Framework\TestCase;

class ReportAmountsTest extends TestCase
{
    public function test_report_totals_remain_exact_beyond_native_integer_and_patient_account_limits(): void
    {
        $this->assertSame('200000000000000000000',ReportAmounts::add('99999999999999999999','100000000000000000001'));
        $this->assertSame('1999999999980.00',ReportAmounts::decimal(ReportAmounts::add('99999999999000','99999999999000')));
        $this->assertSame('-100',ReportAmounts::add('999','-1099'));
        $this->assertSame('-1.00',ReportAmounts::decimal('-100'));
        $this->assertSame('0.000000',ReportAmounts::decimal(ReportAmounts::add('-999','999'),6));
        $this->assertSame('999999999999999999.999999',ReportAmounts::decimal('999999999999999999999999',6));
    }
}
