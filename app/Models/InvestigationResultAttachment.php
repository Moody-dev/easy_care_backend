<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvestigationResultAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationResultAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    public function result(): BelongsTo { return $this->belongsTo(InvestigationResult::class, 'result_id'); }

    protected function casts(): array { return ['byte_size' => 'integer']; }
}
