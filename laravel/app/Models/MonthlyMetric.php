<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyMetric extends Model
{
    protected $table = 'monthly_metrics';
    protected $guarded = [];

    protected $casts = [
        'value' => 'decimal:6',
    ];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    public function scopeKey($query, string $metricKey)
    {
        return $query->where('metric_key', $metricKey);
    }
}
