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
        'study_unit_hours',
    ];

    protected $attributes = [
        'primary_color' => '#f05a28',
    ];

    protected function casts(): array
    {
        return [
            'study_unit_hours' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
