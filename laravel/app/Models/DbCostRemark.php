<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DbCostRemark extends Model
{
    protected $table = 'db_cost_remarks';
    protected $guarded = [];

    public function scopePeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
