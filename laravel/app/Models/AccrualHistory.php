<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccrualHistory extends Model
{
    protected $table = 'accrual_history';
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];
}
