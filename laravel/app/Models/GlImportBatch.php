<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlImportBatch extends Model
{
    protected $table = 'gl_import_batches';
    protected $guarded = [];

    protected $casts = [
        'months_covered' => 'array',
        'imported_at' => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(GlEntry::class, 'batch_id');
    }
}
