<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([])]
class DocumentExport extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentExportFactory> */
    use HasFactory;

    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $hidden = ['bundle'];

    protected function casts(): array
    {
        return ['bundle'=>'encrypted:array', 'byte_size'=>'integer', 'created_at'=>'immutable_datetime', 'expires_at'=>'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Generated documents are immutable. Generate a new copy.'));
        static::deleting(fn () => throw new LogicException('Generated document retention requires a separate approved procedure.'));
    }
}
