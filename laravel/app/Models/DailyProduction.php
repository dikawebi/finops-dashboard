<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyProduction extends Model
{
    protected $table = 'daily_production';
    protected $guarded = [];

    protected $casts = [
        'production_date' => 'date',
        'coal_getting_mt' => 'decimal:6',
        'routes' => 'array',
    ];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
