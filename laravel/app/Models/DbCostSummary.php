<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbCostSummary extends Model
{
    protected $table = 'db_cost_summary';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:6',
    ];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
