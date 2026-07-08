<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $data = $request->validate([
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'app_name' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'footer_text' => ['nullable', 'string', 'max:255'],
            'footer_link' => ['nullable', 'url', 'max:255'],
            'login_background_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'study_unit_hours' => ['nullable', 'array'],
            'study_unit_hours.*' => ['array'],
            'study_unit_hours.*.start' => ['required_with:study_unit_hours', 'date_format:H:i'],
            'study_unit_hours.*.end' => ['required_with:study_unit_hours', 'date_format:H:i'],
        ]);

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
        }

        foreach ($superAdminOnlyFields as $field) {
            if ($request->filled($field)) {
                $settings->{$field} = $data[$field];
            }
        }

        if ($request->has('study_unit_hours')) {
            $settings->study_unit_hours = $data['study_unit_hours'];
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
        ]);
    }
}
