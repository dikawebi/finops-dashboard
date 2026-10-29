<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostAccount extends Model
{
    protected $table = 'cost_accounts';
    protected $guarded = [];

    protected $casts = [
        'is_parent' => 'boolean',
    ];

    public function parent() { return $this->belongsTo(self::class, 'parent_code', 'account_code'); }

    public function children()
    {
        // derajat relasi hierarki berbasis kode (prefix pattern) dinilai di service,
        // model memberikan helper sederhana
        return static::query()
            ->where('account_code', 'like', $this->account_code . '%')
            ->where('account_code', '<>', $this->account_code);
    }

    public function scopeNonParent($query)
    {
        return $query->where('is_parent', false);
    }

    public function head(): string
    {
        return substr($this->account_code, 0, 1);
    }
}
