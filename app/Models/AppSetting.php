<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'primary_color',
        'logo_path',
    ];

    protected $attributes = [
        'primary_color' => '#3db166',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
