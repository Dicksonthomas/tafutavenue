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
     * Mipangilio ya sasa (rangi kuu + logo). Endpoint hii ni ya wazi (public)
     * kwa sababu ukurasa wa login/register nao unahitaji kuonyesha logo/rangi
     * kabla ya mtumiaji kuingia. Kama mtumiaji ame-login (token) na ana rangi
     * yake binafsi (preferred_color), hiyo inatumika badala ya default.
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
        ]);
    }

    /**
     * Admin anabadilisha rangi kuu (default) ya mfumo na/au logo ya App.
     * Logo si ya lazima - Admin anaweza kubadilisha rangi tu bila kugusa logo.
     * Jina la App, namba ya msaada (support phone), na maneno/link ya footer ni
     * Super Admin PEKEE anayeweza kuvibadilisha.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'app_name' => ['nullable', 'string', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'footer_text' => ['nullable', 'string', 'max:255'],
            'footer_link' => ['nullable', 'url', 'max:255'],
        ]);

        $superAdminOnlyFields = ['app_name', 'support_phone', 'footer_text', 'footer_link'];

        if ($request->anyFilled($superAdminOnlyFields) && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Super Admin pekee anaweza kubadilisha mipangilio hii.');
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

        $settings->save();

        ActivityLog::record($request->user()->id, 'settings_updated', "{$request->user()->name} updated the system settings.");

        return response()->json([
            'message' => 'Mipangilio imehifadhiwa.',
            'primary_color' => $settings->primary_color,
            'logo_url' => $settings->logo_path,
            'app_name' => $settings->app_name,
            'support_phone' => $settings->support_phone,
            'footer_text' => $settings->footer_text,
            'footer_link' => $settings->footer_link,
        ]);
    }
}
