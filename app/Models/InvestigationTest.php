<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class InvestigationTest extends Model
{
    /** @use HasFactory<\Database\Factories\InvestigationTestFactory> */
    use HasFactory;

    public const DEPARTMENTS = ['laboratory', 'radiology'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schemas(): HasMany
    {
        return $this->hasMany(InvestigationSchema::class, 'test_id')->orderBy('version');
    }

    public function latestSchema(): HasOne
    {
        return $this->hasOne(InvestigationSchema::class, 'test_id')->ofMany('version', 'max');
    }

    public function requestedItems(): HasMany
    {
        return $this->hasMany(InvestigationItem::class, 'test_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
