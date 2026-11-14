<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
class CurrentBedOccupancy extends Model
{
    protected $table = 'current_bed_occupancies';

    protected $primaryKey = 'bed_id';

    public $incrementing = false;

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(BedAllocation::class);
    }

    public function save(array $options = []): never
    {
        throw new LogicException('Current bed occupancy is a read-only projection of allocation history.');
    }

    public function delete(): never
    {
        throw new LogicException('Release the saved allocation to end occupancy.');
    }
}
