<?php

namespace Database\Factories;

use App\Models\HospitalReferencePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HospitalReferencePrice>
 */
class HospitalReferencePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_id' => HospitalPriceImportFactory::new(),
            'source_kind' => 'consultation',
            'source_file' => 'Consultation Type.csv',
            'source_line' => 2,
            'group_name' => 'Consultation',
            'label' => 'Fixture consultation',
            'item_key' => hash('sha256', fake()->uuid()),
            'amount_micros' => 1000000000,
            'currency' => 'NGN',
            'basis' => 'listed_item',
            'status' => 'ready',
            'issues' => [],
            'raw_cells' => ['Fixture consultation', '1000'],
        ];
    }
}
