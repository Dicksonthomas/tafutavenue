<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'primary_color',
        'logo_path',
        'app_name',
        'support_phone',
        'footer_text',
        'footer_link',
        'login_background_color',
    ];

    protected $attributes = [
        'primary_color' => '#3db166',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
