<?php

namespace Database\Factories;

use App\Models\HmoProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HmoProvider> */
class HmoProviderFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (HmoProvider $provider): void {
            \Illuminate\Support\Facades\DB::table('hmo_catalog_revisions')->insert(['kind'=>'provider','record_id'=>$provider->id,'version'=>1,
                'is_active'=>true,'preauth_required'=>false,'details'=>'{}','actor_id'=>$provider->actor_id,
                'recorded_at'=>$provider->recorded_at->format('Y-m-d H:i:s.u'),'reason'=>$provider->reason]);
        });
    }

    public function definition(): array
    {
        return ['code'=>'synthetic-hmo-'.fake()->uuid(), 'name'=>'Synthetic HMO provider', 'actor_id'=>\App\Models\User::factory(), 'recorded_at'=>fn (): string=>now('UTC')->format('Y-m-d H:i:s.u'), 'reason'=>'Synthetic HMO fixture reason'];
    }
}
