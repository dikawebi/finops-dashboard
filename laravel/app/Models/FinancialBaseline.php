<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialBaseline extends Model
{
    protected $table = 'financial_baselines';
    protected $guarded = [];

    protected $casts = [
        'sections' => 'array',
        'accounts' => 'array',
        'policy' => 'array',
        'cash_flow_enabled' => 'boolean',
        'version' => 'integer',
        'year' => 'integer',
        'comparative_year' => 'integer',
    ];
}
