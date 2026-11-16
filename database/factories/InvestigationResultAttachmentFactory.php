<?php

namespace Database\Factories;

use App\Models\InvestigationResult;
use App\Models\InvestigationResultAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @extends Factory<InvestigationResultAttachment> */
class InvestigationResultAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['result_id' => InvestigationResult::factory(), 'file_id' => function (array $data): int {
            $bytes = "%PDF-1.4\nSynthetic result attachment\n%%EOF";
            $path = now('UTC')->format('Y/m/').Str::uuid().'.pdf';
            Storage::disk('protected')->put($path, $bytes, ['visibility' => 'private']);

            $id = DB::table('files')->insertGetId(['storage_path' => $path, 'original_name' => 'synthetic-result.pdf',
                'mime_type' => 'application/pdf', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'scan_status' => 'not_scanned', 'uploaded_by' => InvestigationResult::findOrFail($data['result_id'])->author_id,
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('investigation_result_file_bindings')->insert(['file_id' => $id, 'item_id' => InvestigationResult::findOrFail($data['result_id'])->item_id]);

            return $id;
        }, 'original_name' => fn (array $data): string => DB::table('files')->find($data['file_id'])->original_name,
            'mime_type' => fn (array $data): string => DB::table('files')->find($data['file_id'])->mime_type,
            'byte_size' => fn (array $data): int => (int) DB::table('files')->find($data['file_id'])->byte_size,
            'sha256' => fn (array $data): string => DB::table('files')->find($data['file_id'])->sha256];
    }
}
