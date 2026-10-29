<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accrual extends Model
{
    protected $table = 'accruals';
    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:6',
        'rate_usd' => 'decimal:6',
        'amount_usd' => 'decimal:6',
    ];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
