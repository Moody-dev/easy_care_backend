<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'daily_rate'])]
class Ward extends Model
{
    /** @use HasFactory<\Database\Factories\WardFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['is_active' => true, 'version' => 1];

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class)->orderBy('label')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer', 'daily_rate' => 'decimal:2'];
    }
}
