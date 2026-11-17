<?php

namespace Database\Factories;

use App\Models\HmoCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HmoCoverage> */
class HmoCoverageFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id'=>\App\Models\BillingAccount::factory(), 'plan_id'=>\App\Models\HmoPlan::factory(), 'member_number'=>'Synthetic member', 'valid_from'=>'2026-01-01', 'valid_until'=>'2026-12-31', 'group_number'=>null, 'employer_name'=>null, 'verification_reference'=>'Synthetic external verification', 'snapshot'=>[], 'actor_id'=>\App\Models\User::factory(), 'recorded_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'), 'reason'=>'Synthetic HMO fixture reason'];
    }
}
