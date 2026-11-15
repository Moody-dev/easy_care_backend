<?php

namespace App\Console\Commands;

use App\Models\CareProgram;
use App\Models\ClinicalTemplate;
use App\Models\ClinicalTemplateVersion;
use App\Models\Permission;
use App\Models\User;
use App\Services\ClinicalPayloads;
use App\Services\WriteTransactions;
use App\Services\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PublishCareForm extends Command
{
    protected $signature = 'care:publish-form {definition : Local JSON file containing a hospital-approved form} {--publisher= : Authorized publishing staff ID}';
    protected $description = 'Publish a versioned hospital-approved care form from the trusted server console';

    public function handle(WriteTransactions $transactions, ClinicalPayloads $payloads, AuditTrail $audit): int
    {
        try {
            $path = $this->argument('definition');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 262144) {
                $this->error('Choose a readable local JSON definition no larger than 256 KiB.');
                return self::FAILURE;
            }
            $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            Validator::make($data, ['type' => ['required',Rule::in([...CareProgram::TYPES,'birth'])],
                'code' => ['required','string','max:32','regex:/^[a-z][a-z0-9_-]*$/D'],
                'title' => ['required','string','max:160'], 'fields' => ['required','array','max:50']])->validate();
            if (array_diff(array_keys($data), ['type','code','title','fields']) !== []) {
                throw new \RuntimeException('Unsupported definition keys.');
            }
            $candidate = new ClinicalTemplateVersion;
            $candidate->fields = $data['fields'];
            $payloads->definition($candidate);
            $transactions->run(function () use ($data, $audit): void {
                Permission::query()->where('code','staff.manage')->lockForUpdate()->firstOrFail();
                $actor = User::query()->lockForUpdate()->findOrFail($this->option('publisher'));
                if (! $actor->is_active || ! $actor->hasPermission('care.forms.publish')) {
                    throw new \RuntimeException('Publisher is not authorized.');
                }
                $code = 'care.'.$data['type'].'.'.$data['code'];
                $template = ClinicalTemplate::query()->where('code', $code)->lockForUpdate()->first();
                if ($template === null) {
                    $template = new ClinicalTemplate;
                    $template->forceFill(['code' => $code,'name' => $data['title'],'created_by' => $actor->id])->save();
                }
                if (! $template->is_active) { throw new \RuntimeException('Restore this template before publishing another version.'); }
                $version = new ClinicalTemplateVersion;
                $version->forceFill(['clinical_template_id' => $template->id, 'version' => (int) $template->versions()->max('version') + 1,
                    'title' => $data['title'],'fields' => $data['fields'],'published_by' => $actor->id,'published_at' => now('UTC')])->save();
                $audit->record('care.form_published', $actor->id, 'care_form', $template->id,
                    ['version' => $version->version, 'record_id' => $version->id], channel: 'console');
            });
            $this->info('The approved care form was published as an immutable version.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('The form was not published. Check the definition and authorized publisher.');
            return self::FAILURE;
        }
    }
}
