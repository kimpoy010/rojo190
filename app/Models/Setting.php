<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key/value app settings, editable at runtime by a superadmin
 * (see Superadmin\SettingsController) without a deploy — currently just
 * 'balancer_switch' (see PoolPayoutCalculator), but the table/model is
 * generic for whatever else needs one later.
 */
class Setting extends Model
{
    protected $fillable = [
        'setting_name',
        'value',
    ];

    public static function get(string $name, ?string $default = null): ?string
    {
        return Cache::remember(
            "setting.{$name}",
            60,
            fn () => static::where('setting_name', $name)->value('value') ?? $default
        );
    }

    public static function set(string $name, ?string $value): void
    {
        static::updateOrCreate(['setting_name' => $name], ['value' => $value]);
        Cache::forget("setting.{$name}");
    }
}
