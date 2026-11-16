<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InvestigationSchema extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationSchemaFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function test(): BelongsTo
    {
        return $this->belongsTo(InvestigationTest::class, 'test_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'schema_json' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
