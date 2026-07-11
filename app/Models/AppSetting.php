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
        'cr_registration_windows',
        'staff_registration_windows',
        'staff_registration_closed_campuses',
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
            'cr_registration_windows' => 'array',
            'staff_registration_windows' => 'array',
            'staff_registration_closed_campuses' => 'array',
            'marquee_enabled' => 'boolean',
            'marquee_until' => 'datetime',
            'maintenance_mode' => 'boolean',
            'maintenance_until' => 'datetime',
        ];
    }

    /**
     * Whether `$now` falls within a campus's optional [open_from, open_until]
     * window (both full datetimes, not just dates). No window, or both
     * bounds blank, means "always open". Missing bounds on one side mean
     * "open from then on, no end" (or vice versa).
     */
    private function withinWindow(array $windows, string $campus): bool
    {
        $window = $windows[$campus] ?? null;

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

    /**
     * Same idea as isCrRegistrationOpenForCampus() - a manual immediate
     * toggle (staff_registration_closed_campuses) combined with an optional
     * scheduled window (staff_registration_windows).
     */
    public function isStaffRegistrationOpenForCampus(string $campus): bool
    {
        if (in_array($campus, $this->staff_registration_closed_campuses ?? [], true)) {
            return false;
        }

        return $this->withinWindow($this->staff_registration_windows ?? [], $campus);
    }

    /**
     * CR registration is closed for a campus either because it was manually
     * toggled closed (cr_registration_closed_campuses - an immediate on/off
     * with no time bound), OR because "now" falls outside that campus's
     * scheduled open window (cr_registration_windows) - both can be used
     * together: the manual toggle for "close it right now", the window for
     * "close/open automatically at a specific date and time".
     */
    public function isCrRegistrationOpenForCampus(string $campus): bool
    {
        if (in_array($campus, $this->cr_registration_closed_campuses ?? [], true)) {
            return false;
        }

        return $this->withinWindow($this->cr_registration_windows ?? [], $campus);
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
