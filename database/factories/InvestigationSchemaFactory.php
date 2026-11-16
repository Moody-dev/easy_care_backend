<?php

namespace Database\Factories;

use App\Models\InvestigationSchema;
use App\Models\InvestigationTest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationSchema> */
class InvestigationSchemaFactory extends Factory
{
    public function definition(): array
    {
        return ['test_id' => InvestigationTest::factory(), 'version' => 1,
            'schema_json' => ['fields' => ['observation' => ['type' => 'text', 'required' => true]]],
            'content_hash' => hash('sha256', 'synthetic definition'), 'published_by' => User::factory(),
            'published_at' => now('UTC'), 'approval_reference' => 'Synthetic approval reference', 'reason' => 'Synthetic publication reason'];
    }
}
