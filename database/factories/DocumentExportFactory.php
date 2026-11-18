<?php

namespace Database\Factories;

use App\Models\DocumentExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentExport>
 */
class DocumentExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'creator_id'=>\App\Models\User::factory(), 'kind'=>'report', 'format'=>'csv',
            'bundle'=>['input'=>[], 'source_hash'=>hash('sha256','fixture'), 'authority_hash'=>hash('sha256','fixture'), 'content'=>'Synthetic retained copy'],
            'sha256'=>hash('sha256','Synthetic retained copy'), 'byte_size'=>strlen('Synthetic retained copy'),
            'created_at'=>now('UTC'), 'expires_at'=>now('UTC')->addDay(),
        ];
    }
}
