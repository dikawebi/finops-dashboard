<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryManualInput extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty_mt' => 'decimal:6',
        'adjustment_usd' => 'decimal:6',
        'sale_price_usd_per_mt' => 'decimal:6',
        'voided_at' => 'datetime',
    ];
}
