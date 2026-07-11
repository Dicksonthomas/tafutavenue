<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class SettingsController extends Controller
{
    /**
     * Current settings (primary color + logo). This endpoint is public
     * because the login/register pages also need to show the logo/color
     * before the user logs in. If the user is logged in (token) and has
     * their own personal color (preferred_color), that is used instead of
     * the default.
     */
    public function show(Request $request): JsonResponse
    {
        $settings = AppSetting::current();

        $primaryColor = $settings->primary_color;

        if ($token = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($token);
            $user = $accessToken?->tokenable;

            if ($user?->preferred_color) {
                $primaryColor = $user->preferred_color;
            }
        }

        return response()->json([
            'primary_color' => $primaryColor,
            'default_color' => $settings->primary_color,
            'logo_url' => $settings->logo_path,
            'app_name' => $settings->app_name,
            'support_phone' => $settings->support_phone,
            'footer_text' => $settings->footer_text,
            'footer_link' => $settings->footer_link,
            'login_background_color' => $settings->login_background_color,
            'study_unit_hours' => $settings->study_unit_hours,
            'cr_registration_closed_campuses' => $settings->cr_registration_closed_campuses ?? [],
            'cr_registration_windows' => $settings->cr_registration_windows ?? [],
            'staff_registration_windows' => $settings->staff_registration_windows ?? [],
            'staff_registration_closed_campuses' => $settings->staff_registration_closed_campuses ?? [],
            'marquee_enabled' => $settings->marquee_enabled,
            'marquee_until' => $settings->marquee_until,
            'maintenance_mode' => $settings->maintenance_mode,
            'maintenance_until' => $settings->maintenance_until,
        ]);
    }

    /**
     * Admin changes the system's default primary color and/or the App logo.
     * The logo is optional - an Admin can change just the color without
     * touching the logo. App name, support phone, footer text/link, and the
     * login page background color can ONLY be changed by a Super Admin.
     */
    public function update(Request $request): JsonResponse
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $campuses = ['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'];

        $data = $request->validate([
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'reset_logo' => ['nullable', 'boolean'],
            'app_name' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'footer_text' => ['nullable', 'string', 'max:255'],
            'footer_link' => ['nullable', 'url', 'max:255'],
            'login_background_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'study_unit_hours' => ['nullable', 'array'],
            'study_unit_hours.*' => ['array'],
            'study_unit_hours.*.start' => ['required_with:study_unit_hours', 'date_format:H:i'],
            'study_unit_hours.*.end' => ['required_with:study_unit_hours', 'date_format:H:i'],
            'cr_registration_closed_campuses' => ['nullable', 'array'],
            'cr_registration_closed_campuses.*' => [Rule::in($campuses)],
            'cr_registration_windows' => ['nullable', 'array'],
            'cr_registration_windows.*' => ['array'],
            'cr_registration_windows.*.open_from' => ['nullable', 'date'],
            'cr_registration_windows.*.open_until' => ['nullable', 'date'],
            'staff_registration_windows' => ['nullable', 'array'],
            'staff_registration_windows.*' => ['array'],
            'staff_registration_windows.*.open_from' => ['nullable', 'date'],
            'staff_registration_windows.*.open_until' => ['nullable', 'date'],
            'staff_registration_closed_campuses' => ['nullable', 'array'],
            'staff_registration_closed_campuses.*' => [Rule::in($campuses)],
            'marquee_enabled' => ['sometimes', 'boolean'],
            'marquee_until' => ['nullable', 'date'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'maintenance_until' => ['nullable', 'date'],
        ]);

        foreach (['cr_registration_windows', 'staff_registration_windows'] as $windowField) {
            if ($request->has($windowField)) {
                $invalidCampus = collect(array_keys($request->input($windowField, [])))
                    ->first(fn ($campus) => ! in_array($campus, $campuses, true));

                if ($invalidCampus) {
                    abort(422, "Unrecognized campus: {$invalidCampus}.");
                }
            }
        }

        if ($request->has('study_unit_hours')) {
            $invalidDay = collect(array_keys($request->input('study_unit_hours', [])))
                ->first(fn ($day) => ! in_array($day, $days, true));

            if ($invalidDay) {
                abort(422, "Unrecognized day: {$invalidDay}.");
            }
        }

        $superAdminOnlyFields = ['app_name', 'support_phone', 'footer_text', 'footer_link', 'login_background_color'];

        if ($request->anyFilled($superAdminOnlyFields) && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a Super Admin can change these settings.');
        }

        $settings = AppSetting::current();

        if (! empty($data['primary_color'])) {
            $settings->primary_color = $data['primary_color'];
        }

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $base64 = base64_encode(file_get_contents($file->getRealPath()));
            $settings->logo_path = 'data:'.$file->getMimeType().';base64,'.$base64;
        } elseif ($request->boolean('reset_logo')) {
            // Clears the uploaded logo so the frontend's bundled default
            // (Mzumbe crest) fallback shows again everywhere.
            $settings->logo_path = null;
        }

        foreach ($superAdminOnlyFields as $field) {
            if ($request->filled($field)) {
                $settings->{$field} = $data[$field];
            }
        }

        if ($request->has('study_unit_hours')) {
            $settings->study_unit_hours = $data['study_unit_hours'];
        }

        if ($request->has('cr_registration_closed_campuses')) {
            $settings->cr_registration_closed_campuses = $this->mergeClosedCampuses(
                $data['cr_registration_closed_campuses'] ?? [],
                $settings->cr_registration_closed_campuses ?? [],
                $request->user()->campusScope(),
                $campuses,
            );
        }

        if ($request->has('cr_registration_windows')) {
            $settings->cr_registration_windows = $this->mergeWindows(
                $data['cr_registration_windows'] ?? [],
                $settings->cr_registration_windows ?? [],
                $request->user()->campusScope(),
                $campuses,
            );
        }

        if ($request->has('marquee_enabled')) {
            $settings->marquee_enabled = $request->boolean('marquee_enabled');
        }

        if ($request->has('marquee_until')) {
            $settings->marquee_until = $data['marquee_until'] ?? null;
        }

        if ($request->has('staff_registration_windows')) {
            $settings->staff_registration_windows = $this->mergeWindows(
                $data['staff_registration_windows'] ?? [],
                $settings->staff_registration_windows ?? [],
                $request->user()->campusScope(),
                $campuses,
            );
        }

        if ($request->has('staff_registration_closed_campuses')) {
            $settings->staff_registration_closed_campuses = $this->mergeClosedCampuses(
                $data['staff_registration_closed_campuses'] ?? [],
                $settings->staff_registration_closed_campuses ?? [],
                $request->user()->campusScope(),
                $campuses,
            );
        }

        if ($request->has('maintenance_mode')) {
            $settings->maintenance_mode = $request->boolean('maintenance_mode');
        }

        if ($request->has('maintenance_until')) {
            $settings->maintenance_until = $data['maintenance_until'] ?? null;
        }

        $settings->save();

        ActivityLog::record($request->user()->id, 'settings_updated', "{$request->user()->name} updated the system settings.");

        return response()->json([
            'message' => 'Settings saved.',
            'primary_color' => $settings->primary_color,
            'logo_url' => $settings->logo_path,
            'app_name' => $settings->app_name,
            'support_phone' => $settings->support_phone,
            'footer_text' => $settings->footer_text,
            'footer_link' => $settings->footer_link,
            'login_background_color' => $settings->login_background_color,
            'study_unit_hours' => $settings->study_unit_hours,
            'cr_registration_closed_campuses' => $settings->cr_registration_closed_campuses ?? [],
            'cr_registration_windows' => $settings->cr_registration_windows ?? [],
            'staff_registration_windows' => $settings->staff_registration_windows ?? [],
            'staff_registration_closed_campuses' => $settings->staff_registration_closed_campuses ?? [],
            'marquee_enabled' => $settings->marquee_enabled,
            'marquee_until' => $settings->marquee_until,
            'maintenance_mode' => $settings->maintenance_mode,
            'maintenance_until' => $settings->maintenance_until,
        ]);
    }

    /**
     * Merge a requested "closed campuses" list into the existing one - a
     * regular Admin may only change their OWN campus's membership (any other
     * campus in the payload is ignored, preserving what was already set for
     * it); a Super Admin's payload (campusScope() === null) is trusted as
     * given. Shared by CR and Staff registration's immediate on/off toggle.
     */
    private function mergeClosedCampuses(array $requested, array $existing, ?string $campusScope, array $campuses): array
    {
        if ($campusScope) {
            $others = array_values(array_diff($existing, [$campusScope]));

            return in_array($campusScope, $requested, true) ? [...$others, $campusScope] : $others;
        }

        return array_values(array_intersect($requested, $campuses));
    }

    /**
     * Same scoping rule as mergeClosedCampuses(), but for a per-campus
     * [open_from, open_until] scheduled window instead of a plain toggle.
     * Shared by CR and Staff registration windows.
     */
    private function mergeWindows(array $requested, array $existing, ?string $campusScope, array $campuses): array
    {
        if ($campusScope) {
            if (array_key_exists($campusScope, $requested)) {
                $existing[$campusScope] = [
                    'open_from' => $requested[$campusScope]['open_from'] ?? null,
                    'open_until' => $requested[$campusScope]['open_until'] ?? null,
                ];
            }

            return $existing;
        }

        return collect($requested)
            ->only($campuses)
            ->map(fn ($window) => [
                'open_from' => $window['open_from'] ?? null,
                'open_until' => $window['open_until'] ?? null,
            ])
            ->all();
    }
}
