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
        'cr_registration_closed_campuses',
        'marquee_enabled',
        'marquee_until',
    ];

    protected $attributes = [
        'primary_color' => '#FF7F50',
        'marquee_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'study_unit_hours' => 'array',
            'cr_registration_closed_campuses' => 'array',
            'marquee_enabled' => 'boolean',
            'marquee_until' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
