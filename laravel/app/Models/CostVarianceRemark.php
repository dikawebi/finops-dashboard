<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostVarianceRemark extends Model
{
    protected $table = 'cost_variance_remarks';
    protected $guarded = [];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
