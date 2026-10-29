<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlEntry extends Model
{
    protected $table = 'gl_entries';
    protected $guarded = [];

    protected $casts = [
        'fuel_quantity' => 'decimal:6',
        'amount_txn' => 'decimal:6',
        'amount_idr' => 'decimal:6',
        'amount' => 'decimal:6',
        'amount_reporting' => 'decimal:6',
        'entry_date' => 'date',
        'created_datetime' => 'datetime',
    ];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    public function batch()
    {
        return $this->belongsTo(GlImportBatch::class, 'batch_id');
    }
}
