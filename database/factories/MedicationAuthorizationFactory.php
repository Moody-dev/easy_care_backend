<?php

namespace Database\Factories;

use App\Models\MedicationAuthorization;
use App\Models\PrescriptionItem;
use App\Models\DoctorAssignment;
use App\Models\Drug;
use App\Services\PrescriptionPayloads;
use App\Services\StockCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicationAuthorization>
 */
class MedicationAuthorizationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (MedicationAuthorization $authorization): void {
            $authorization->events()->forceCreate(['version' => 1, 'action' => 'authorize', 'record_id' => $authorization->id,
                'actor_id' => $authorization->actor_id, 'recorded_at' => $authorization->recorded_at, 'reason' => $authorization->reason]);
        });
    }
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['maximum_quantity_micros' => 1000000, 'recorded_at' => fn (): string => now('UTC')->format('Y-m-d H:i:s.u'),
            'reason' => 'Synthetic signed catalog authorization'];
    }

    public function forSignedLine(PrescriptionItem $item, DoctorAssignment $assignment, Drug $drug): static
    {
        return $this->state(fn (): array => ['prescription_item_id' => $item->id, 'drug_id' => $drug->id, 'drug_version' => $drug->version,
            'assignment_id' => $assignment->id, 'assignment_version' => $assignment->version, 'actor_id' => $assignment->doctor_id,
            'snapshot' => ['prescription' => $item->only(PrescriptionPayloads::ITEM_FIELDS), 'drug' => StockCatalog::snapshot('drugs', $drug)]]);
    }
}
