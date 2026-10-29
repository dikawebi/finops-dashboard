<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $table = 'inventory_movements';
    protected $guarded = [];

    protected $casts = [
        'qty_mt' => 'decimal:6',
        'amount_usd' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    public function scopeLocation($query, string $location)
    {
        return $query->where('location', $location);
    }
}
