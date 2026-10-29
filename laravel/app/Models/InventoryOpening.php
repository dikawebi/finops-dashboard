<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryOpening extends Model
{
    protected $table = 'inventory_openings';
    protected $guarded = [];

    protected $casts = [
        'qty_mt' => 'decimal:6',
        'value_usd' => 'decimal:6',
        'cost_per_mt' => 'decimal:6',
    ];
}
