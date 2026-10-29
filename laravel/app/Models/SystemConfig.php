<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemConfig extends Model
{
    protected $table = 'system_configs';
    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, $default = null)
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
