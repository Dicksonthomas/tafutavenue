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
        'staff_registration_open_from',
        'staff_registration_open_until',
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
            'staff_registration_open_from' => 'date',
            'staff_registration_open_until' => 'date',
        ];
    }

    /**
     * Whether Staff self-registration is currently open. Null bounds mean
     * "always open" on that side - e.g. only a from-date set means "open
     * from then on, no end".
     */
    public function isStaffRegistrationOpen(): bool
    {
        $today = now()->toDateString();

        if ($this->staff_registration_open_from && $today < $this->staff_registration_open_from->toDateString()) {
            return false;
        }

        if ($this->staff_registration_open_until && $today > $this->staff_registration_open_until->toDateString()) {
            return false;
        }

        return true;
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
