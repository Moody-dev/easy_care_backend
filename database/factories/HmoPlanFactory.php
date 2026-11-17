<?php

namespace Database\Factories;

use App\Models\HmoPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HmoPlan> */
class HmoPlanFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (HmoPlan $plan): void {
            \Illuminate\Support\Facades\DB::table('hmo_catalog_revisions')->insert(['kind'=>'plan','record_id'=>$plan->id,'version'=>1,
                'is_active'=>true,'preauth_required'=>false,'details'=>'{}','actor_id'=>$plan->actor_id,
                'recorded_at'=>$plan->recorded_at->format('Y-m-d H:i:s.u'),'reason'=>$plan->reason]);
        });
    }

    public function definition(): array
    {
        return ['provider_id'=>\App\Models\HmoProvider::factory(), 'code'=>'synthetic-plan-'.fake()->uuid(), 'name'=>'Synthetic HMO plan', 'actor_id'=>\App\Models\User::factory(), 'recorded_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'), 'reason'=>'Synthetic HMO fixture reason'];
    }
}
