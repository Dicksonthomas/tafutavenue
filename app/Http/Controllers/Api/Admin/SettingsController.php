<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'logo_url' => $settings->logo_path ? Storage::disk('public')->url($settings->logo_path) : null,
        ]);
    }

    /**
     * Admin anabadilisha rangi kuu (default) ya mfumo na/au logo ya App.
     * Logo si ya lazima - Admin anaweza kubadilisha rangi tu bila kugusa logo.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $settings = AppSetting::current();

        if (! empty($data['primary_color'])) {
            $settings->primary_color = $data['primary_color'];
        }

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $settings->logo_path = $request->file('logo')->store('branding', 'public');
        }

        $settings->save();

        return response()->json([
            'message' => 'Mipangilio imehifadhiwa.',
            'primary_color' => $settings->primary_color,
            'logo_url' => $settings->logo_path ? Storage::disk('public')->url($settings->logo_path) : null,
        ]);
    }
}
