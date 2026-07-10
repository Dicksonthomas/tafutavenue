<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Controller;
use App\Mail\CrCredentialsMail;
use App\Models\ActivityLog;
use App\Models\CustomDepartment;
use App\Models\User;
use App\Services\CrEmailGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthController extends Controller
{
    /**
     * CR (Class Representative) registration. Admin accounts are not created
     * here for security reasons - they are created by an Admin or a seeder.
     *
     * The CR's email is not chosen by them - it is generated automatically
     * from Fullname + Reg No (e.g. "Dickson Musa Thomas" + "14322055/T.25"
     * -> dickson.thomas25@mustudent.ac.tz). The password is also generated
     * automatically and sent to that email - it never appears in the response.
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

        // If this CR typed a department that isn't already known for their
        // faculty (hardcoded or previously custom-added), save it so the
        // next registrant from that faculty/department sees it in the list
        // instead of having to type it too - see
        // ReferenceDataController::departments().
        $department = trim($data['department']);
        $knownDepartments = ReferenceDataController::knownDepartmentNamesFor($data['faculty']);

        if (! in_array(mb_strtolower($department), $knownDepartments, true)) {
            CustomDepartment::firstOrCreate(['faculty' => $data['faculty'], 'name' => $department]);
        }

        $user = User::create([
            ...$data,
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'role' => 'cr',
        ]);

        dispatch(function () use ($user, $plainPassword) {
            try {
                Mail::to($user->email)->send(new CrCredentialsMail($user, $plainPassword));
            } catch (\Throwable $e) {
                Log::error("Failed to email registration credentials to {$user->email}: ".$e->getMessage());
            }
        })->afterResponse();

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => "Account created. Password has been sent to email: {$user->email}",
        ], 201);
    }

    /**
     * If the generated email already exists (e.g. similar names), append a
     * number to the end of the local-part until an unused email is found.
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

        throw ValidationException::withMessages(['reg_no' => 'Failed to generate a unique email. Contact the Admin.']);
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
                'message' => 'Your account has been suspended. Contact the Admin.',
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

        return response()->json(['message' => 'Logged out successfully.']);
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
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->update(['password' => Hash::make($data['new_password'])]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Any user (CR or Admin) can set their own personal color preference
     * (overriding the system default color, but only for their own account).
     * 'color' = null resets it back to the system default.
     */
    public function updateColorPreference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $request->user()->update(['preferred_color' => $data['color'] ?? null]);

        return response()->json(['message' => 'Your personal color preference has been saved.']);
    }
}
