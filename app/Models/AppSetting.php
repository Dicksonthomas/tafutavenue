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
        'staff_registration_windows',
        'marquee_enabled',
        'marquee_until',
        'maintenance_mode',
        'maintenance_until',
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
            'staff_registration_windows' => 'array',
            'marquee_enabled' => 'boolean',
            'marquee_until' => 'datetime',
            'maintenance_mode' => 'boolean',
            'maintenance_until' => 'datetime',
        ];
    }

    /**
     * Whether Staff self-registration is currently open FOR A GIVEN CAMPUS.
     * Each campus has its own optional [open_from, open_until] window (both
     * full datetimes, not just dates); a campus with no entry, or with both
     * bounds blank, is always open. Missing bounds on one side mean "open
     * from then on, no end" (or vice versa).
     */
    public function isStaffRegistrationOpenForCampus(string $campus): bool
    {
        $window = ($this->staff_registration_windows ?? [])[$campus] ?? null;

        if (! $window) {
            return true;
        }

        $now = now();

        if (! empty($window['open_from']) && $now->lt(\Illuminate\Support\Carbon::parse($window['open_from']))) {
            return false;
        }

        if (! empty($window['open_until']) && $now->gt(\Illuminate\Support\Carbon::parse($window['open_until']))) {
            return false;
        }

        return true;
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
