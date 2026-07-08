<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Mail\CrCredentialsMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\CrEmailGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthController extends Controller
{
    /**
     * Usajili wa CR (Class Representative). Akaunti za Admin haziundwi hapa
     * kwa sababu za usalama - zinaundwa na Admin mwenyewe au seeder.
     *
     * Email ya CR haiandikwi na yeye - inatengenezwa kiotomatiki kutoka
     * Fullname + Reg No (mfano: "Dickson Musa Thomas" + "14322055/T.25"
     * -> dickson.thomas25@mustudent.ac.tz). Password nayo hutengenezwa
     * kiotomatiki na kutumwa kwenye email hiyo - haionekani kwenye response.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'reg_no' => ['required', 'string', 'max:50', Rule::unique('users', 'reg_no')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'faculty' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'program' => ['required', 'string', 'max:255'],
            'level' => ['required', Rule::in(['Certificate', 'Diploma', 'Degree', 'Masters', 'PhD'])],
        ]);

        try {
            $generated = CrEmailGenerator::generate($data['name'], $data['reg_no']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reg_no' => $e->getMessage()]);
        }

        $email = $this->resolveUniqueEmail($generated['email']);
        $plainPassword = Str::password(10, symbols: false);

        $user = User::create([
            ...$data,
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'role' => 'cr',
        ]);

        Mail::to($user->email)->queue(new CrCredentialsMail($user, $plainPassword));

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => "Akaunti imetengenezwa. Password imetumwa kwenye email: {$user->email}",
        ], 201);
    }

    /**
     * Kama email iliyotengenezwa tayari ipo (mfano majina yanayofanana), ongeza
     * namba mwishoni mwa local-part mpaka ipatikane email isiyotumika.
     */
    private function resolveUniqueEmail(string $email): string
    {
        if (! User::where('email', $email)->exists()) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        for ($i = 2; $i < 100; $i++) {
            $candidate = "{$local}.{$i}@{$domain}";
            if (! User::where('email', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['reg_no' => 'Imeshindikana kutengeneza email ya kipekee. Wasiliana na Admin.']);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            $attemptedUser = User::where('email', $credentials['email'])->first();

            ActivityLog::record(
                $attemptedUser?->id,
                'login_failed',
                "Failed login attempt for \"{$credentials['email']}\" (wrong password or unknown account)."
            );

            return response()->json([
                'message' => 'Incorrect username or password. Please check and try again.',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            ActivityLog::record($user->id, 'login_blocked', "{$user->name} tried to log in but their account is suspended.");

            return response()->json([
                'message' => 'Akaunti yako imesimamishwa. Wasiliana na Admin.',
            ], 403);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        ActivityLog::record($user->id, 'login_success', "{$user->name} logged in.");

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Umetoka kwa mafanikio.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Password ya sasa si sahihi.',
            ], 422);
        }

        $user->update(['password' => Hash::make($data['new_password'])]);

        return response()->json(['message' => 'Password imebadilishwa kwa mafanikio.']);
    }

    /**
     * Kila mtumiaji (CR au Admin) anaweza kuweka rangi yake binafsi
     * (inayozidi rangi ya default ya mfumo, lakini kwa akaunti yake tu).
     * 'color' = null inarudisha kwenye default ya mfumo.
     */
    public function updateColorPreference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $request->user()->update(['preferred_color' => $data['color'] ?? null]);

        return response()->json(['message' => 'Rangi yako binafsi imehifadhiwa.']);
    }
}
