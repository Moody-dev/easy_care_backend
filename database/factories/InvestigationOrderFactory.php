<?php

namespace Database\Factories;

use App\Models\DoctorAssignment;
use App\Models\InvestigationOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationOrder> */
class InvestigationOrderFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_id' => DoctorAssignment::factory(),
            'encounter_id' => fn (array $data): int => DoctorAssignment::findOrFail($data['assignment_id'])->encounter_id,
            'patient_id' => fn (array $data): int => DoctorAssignment::findOrFail($data['assignment_id'])->encounter->patient_id,
            'ordered_by' => fn (array $data): int => DoctorAssignment::findOrFail($data['assignment_id'])->doctor_id,
            'assignment_version' => fn (array $data): int => DoctorAssignment::findOrFail($data['assignment_id'])->version,
            'requested_at' => fn (array $data): string => DoctorAssignment::findOrFail($data['assignment_id'])->assigned_at->addMinute()->format('Y-m-d H:i:s.u'),
            'last_changed_at' => fn (array $data): string => $data['requested_at'], 'version' => 1,
            'clinical_context' => 'Synthetic investigation context', 'reason' => 'Synthetic investigation request'];
    }
}
