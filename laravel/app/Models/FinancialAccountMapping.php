<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialAccountMapping extends Model
{
    protected $table = 'financial_account_mappings';
    protected $guarded = [];

    protected $casts = [
        'reference_sep' => 'decimal:6',
        'reference_oct' => 'decimal:6',
    ];
}
