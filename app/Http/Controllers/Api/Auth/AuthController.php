<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Controller;
use App\Mail\RegistrationPendingMail;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\CustomDepartment;
use App\Models\Notification;
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
     * automatically and returned directly in this response (not emailed -
     * registration deliberately has no mail-server dependency, so it can't
     * be slowed down or blocked by one).
     *
     * The account is created INACTIVE and unapproved, same as Staff - an
     * Admin must approve it (see UserAdminController::approve()) before it
     * can be used to log in. No Sanctum token is issued here for the same
     * reason as registerStaff(): only login() enforces the approval gate,
     * so a pending account must never hold a usable token.
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

        $closedCampuses = AppSetting::current()->cr_registration_closed_campuses ?? [];
        if (in_array($data['campus'], $closedCampuses, true)) {
            throw ValidationException::withMessages(['campus' => 'CR registration is closed for this campus. Contact the Admin, or register as Staff instead.']);
        }

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
            'is_active' => false,
            'approved_at' => null,
        ]);

        $this->notifyAdminsOfPendingUser($user);

        return response()->json([
            'user' => $user,
            'password' => $plainPassword,
            'message' => 'Registration submitted. An Admin must approve your account before you can log in. Save your password below - you will need it once approved.',
        ], 201);
    }

    /**
     * Staff self-registration. Unlike CR, Staff choose their own (official
     * work) email and identify themselves with a Staff ID/Payroll No
     * instead of a Reg No - there is no academic profile (faculty/program/
     * level/year). The account is created INACTIVE and unapproved; an Admin
     * must approve it (see UserAdminController::approve()) before it can be
     * used to log in. No Sanctum token is issued here - only login() issues
     * tokens, and login() is what enforces the approval gate, so a pending
     * account can never obtain a usable token.
     */
    public function registerStaff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'staff_id' => ['required', 'string', 'max:50', Rule::unique('users', 'staff_id')->whereNull('deleted_at')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:255'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
        ]);

        $plainPassword = Str::password(10, symbols: false);

        $user = User::create([
            ...$data,
            'password' => Hash::make($plainPassword),
            'role' => 'staff',
            'is_active' => false,
            'approved_at' => null,
        ]);

        $this->notifyAdminsOfPendingUser($user);

        return response()->json([
            'user' => $user,
            'password' => $plainPassword,
            'message' => 'Registration submitted. An Admin must approve your account before you can log in. Save your password below - you will need it once approved.',
        ], 201);
    }

    /**
     * Tell that campus's Admins (and every Super Admin) that a new CR/Staff
     * account is waiting for approval - a DB notification plus an email,
     * since a missed pending-approval notice permanently blocks the
     * registrant from ever logging in (more consequential than a missed
     * booking review, which is DB-only). Mail failures must never break
     * registration itself.
     */
    private function notifyAdminsOfPendingUser(User $registrant): void
    {
        $label = $registrant->role === 'staff' ? 'Staff' : 'CR';

        $adminIds = User::where('role', 'admin')
            ->where(fn ($q) => $q->where('is_super_admin', true)->orWhere('campus', $registrant->campus))
            ->pluck('id');

        $now = now();
        $rows = $adminIds->map(fn ($adminId) => [
            'user_id' => $adminId,
            'type' => $registrant->role === 'staff' ? 'staff_pending' : 'cr_pending',
            'title' => "{$registrant->name} registered as {$label} and needs approval",
            'body' => $registrant->role === 'staff' ? $registrant->position : $registrant->reg_no,
            'booking_id' => null,
            'announcement_id' => null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            Notification::insert($rows);
        }

        $adminEmails = User::whereIn('id', $adminIds)->pluck('email');

        foreach ($adminEmails as $adminEmail) {
            try {
                Mail::to($adminEmail)->send(new RegistrationPendingMail($registrant));
            } catch (\Throwable $e) {
                Log::error("Failed to email {$label}-pending notice to {$adminEmail}: ".$e->getMessage());
            }
        }
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
            $isPending = in_array($user->role, ['cr', 'staff'], true) && ! $user->approved_at;
            $message = $isPending
                ? 'Your account is pending Admin approval. Please check back later.'
                : 'Your account has been suspended. Contact the Admin.';

            ActivityLog::record($user->id, 'login_blocked', $isPending
                ? "{$user->name} tried to log in but their account is still pending Admin approval."
                : "{$user->name} tried to log in but their account is suspended.");

            return response()->json(['message' => $message], 403);
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
