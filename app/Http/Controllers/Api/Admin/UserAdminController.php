<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CrCredentialsMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\CrEmailGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserAdminController extends Controller
{
    private const VALID_LEVELS = ['Certificate', 'Diploma', 'Degree', 'Masters', 'PhD'];

    /**
     * Filtered users query (q/campus/faculty/department/program/level/
     * year_of_study/sex), used by both index() and exportPdf(). Scoped to
     * `role` (default 'cr') - Staff use the same endpoint with `?role=staff`
     * (see UserAdminController's frontend counterpart, admin/staff/page.tsx).
     */
    private function filteredUsersQuery(Request $request): Builder
    {
        $request->validate([
            'role' => ['sometimes', Rule::in(['cr', 'staff'])],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended'])],
        ]);

        $campusScope = $request->user()->campusScope();
        $role = $request->query('role', 'cr');

        return User::query()
            ->where('role', $role)
            ->when($request->query('status') === 'pending', fn ($q) => $q->where('is_active', false)->whereNull('approved_at'))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'suspended', fn ($q) => $q->where('is_active', false)->whereNotNull('approved_at'))
            ->when($request->filled('q'), function ($query) use ($request, $role) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q, $role) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");

                    if ($role === 'staff') {
                        $w->orWhere('staff_id', 'like', "%{$q}%")
                            ->orWhere('position', 'like', "%{$q}%");
                    } else {
                        $w->orWhere('reg_no', 'like', "%{$q}%")
                            ->orWhere('program', 'like', "%{$q}%");
                    }
                });
            })
            // A regular Admin is confined to their own campus (the 'campus'
            // filter in the request is ignored for them); a Super Admin can
            // filter freely.
            ->when($campusScope, fn ($query) => $query->where('campus', $campusScope))
            ->when(! $campusScope && $request->filled('campus'), fn ($query) => $query->where('campus', $request->string('campus')))
            ->when($request->filled('faculty'), fn ($query) => $query->where('faculty', $request->string('faculty')))
            ->when($request->filled('department'), fn ($query) => $query->where('department', $request->string('department')))
            ->when($request->filled('program'), fn ($query) => $query->where('program', $request->string('program')))
            ->when($request->filled('level'), fn ($query) => $query->where('level', $request->string('level')))
            ->when($request->filled('year_of_study'), fn ($query) => $query->where('year_of_study', $request->integer('year_of_study')))
            ->when($request->filled('sex'), fn ($query) => $query->where('sex', $request->string('sex')))
            // Pending (unapproved) accounts sort first, so they're easy to
            // spot without needing the explicit status=pending filter.
            ->orderByRaw('CASE WHEN is_active = 0 AND approved_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name');
    }

    /**
     * List of all CRs (for the Admin to view/search).
     */
    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $users = $this->filteredUsersQuery($request)->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Download the CR list (PDF) filtered the same way as index(), including
     * every matching CR (not just one page) - for printing.
     */
    public function exportPdf(Request $request): Response|JsonResponse
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(60);

        try {
            $users = $this->filteredUsersQuery($request)->get();

            $pdf = Pdf::loadView('reports.students-pdf', [
                'users' => $users,
                'filters' => $request->only(['campus', 'faculty', 'department', 'program', 'level', 'year_of_study', 'sex', 'q']),
                'generatedAt' => now(),
            ])->setPaper('a4', 'landscape');

            return $pdf->download('cr_list.pdf');
        } catch (\Throwable $e) {
            Log::error('CR list PDF export failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Failed to generate PDF. Try again or contact the system administrator.',
            ], 500);
        }
    }

    /**
     * Admin registers a single CR or Staff member directly - see
     * storeStaff() for the Staff branch. If 'reg_no' is provided, the email
     * is generated automatically (same as normal registration). If the Reg
     * No has a year that is too old (before 2022) or the CR doesn't have a
     * valid Reg No, the Admin can leave 'reg_no' blank and set 'email'
     * directly instead. The password is generated automatically and sent
     * to the email - it never appears in this response.
     */
    public function store(Request $request): JsonResponse
    {
        $role = $request->input('role', 'cr');
        abort_unless(in_array($role, ['cr', 'staff'], true), 422);

        if ($role === 'staff') {
            return $this->storeStaff($request);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'reg_no' => ['nullable', 'string', 'max:50', Rule::unique('users', 'reg_no')->whereNull('deleted_at')],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'faculty' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'program' => ['required', 'string', 'max:255'],
            'level' => ['required', Rule::in(self::VALID_LEVELS)],
            'year_of_study' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        // A regular Admin cannot register a CR for a campus other than their own.
        if ($campusScope = $request->user()->campusScope()) {
            $data['campus'] = $campusScope;
        }

        if (empty($data['reg_no']) && empty($data['email'])) {
            throw ValidationException::withMessages([
                'email' => 'Provide a Reg No (to auto-generate the email) or an email directly.',
            ]);
        }

        $email = $data['email'] ?? null;

        if (! empty($data['reg_no'])) {
            try {
                $generated = CrEmailGenerator::generate($data['name'], $data['reg_no']);
                $email = $this->resolveUniqueEmail($generated['email']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['reg_no' => $e->getMessage()]);
            }
        }

        $plainPassword = Str::password(10, symbols: false);

        $user = User::create([
            ...$data,
            'email' => $email,
            'password' => Hash::make($plainPassword),
            'role' => 'cr',
        ]);

        dispatch(function () use ($user, $plainPassword) {
            Mail::to($user->email)->send(new CrCredentialsMail($user, $plainPassword));
        })->afterResponse();

        ActivityLog::record($request->user()->id, 'cr_created', "{$request->user()->name} registered a new CR: {$user->name}.");

        return response()->json([
            'user' => $user,
            'message' => "CR added. Password has been sent to email: {$user->email}",
        ], 201);
    }

    /**
     * Admin registers a Staff member directly - unlike self-registration,
     * this account is immediately active (Admin-created = already trusted,
     * same reasoning as CR added by an Admin being instantly active). The
     * password is returned directly in the response (not emailed) -
     * consistent with Staff's own self-registration, avoiding the
     * mail-server fragility already fixed once for CR.
     */
    private function storeStaff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'staff_id' => ['required', 'string', 'max:50', Rule::unique('users', 'staff_id')->whereNull('deleted_at')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:255'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
        ]);

        if ($campusScope = $request->user()->campusScope()) {
            $data['campus'] = $campusScope;
        }

        $plainPassword = Str::password(10, symbols: false);

        $user = User::create([
            ...$data,
            'password' => Hash::make($plainPassword),
            'role' => 'staff',
            'is_active' => true,
            'approved_at' => now(),
        ]);

        ActivityLog::record($request->user()->id, 'staff_created', "{$request->user()->name} registered a new Staff member: {$user->name}.");

        return response()->json([
            'user' => $user,
            'password' => $plainPassword,
            'message' => 'Staff added and already active. Save the password below - it will not be shown again.',
        ], 201);
    }

    /**
     * Download the sample (template) CSV file for bulk-importing CRs.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $columns = ['name', 'reg_no', 'phone', 'campus', 'sex', 'faculty', 'department', 'program', 'level'];
        $example = ['Dickson Musa Thomas', '14322055/T.25', '0712345678', 'morogoro_main', 'male', 'Faculty of Science', 'Computer Science', 'BSc. Computer Science', 'Degree'];

        return response()->streamDownload(function () use ($columns, $example) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $example);
            fclose($handle);
        }, 'cr_import_template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Bulk-import CRs from a CSV file with columns:
     * name,reg_no,phone,faculty,department,program,level
     *
     * The email is generated automatically from name+reg_no for each row.
     * Reg No entries with a year before 2022 are skipped (the Admin should
     * contact that CR directly).
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $campusScope = $request->user()->campusScope();

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $created = [];
        $skipped = [];

        while (($row = fgetcsv($handle)) !== false) {
            $line = array_combine($header, $row);
            $label = $line['reg_no'] ?? ($line['name'] ?? '(no name)');

            if (empty($line['reg_no']) || User::where('reg_no', $line['reg_no'])->exists()) {
                $skipped[] = "{$label} (empty reg_no or already exists)";

                continue;
            }

            if (! in_array($line['level'], self::VALID_LEVELS, true)) {
                $skipped[] = "{$label} (invalid level)";

                continue;
            }

            try {
                $generated = CrEmailGenerator::generate($line['name'], $line['reg_no']);
            } catch (InvalidArgumentException $e) {
                $skipped[] = "{$label} ({$e->getMessage()})";

                continue;
            }

            $email = $this->resolveUniqueEmail($generated['email']);
            $plainPassword = Str::password(10, symbols: false);

            $user = User::create([
                'name' => $line['name'],
                'reg_no' => $line['reg_no'],
                'email' => $email,
                'phone' => $line['phone'] ?? null,
                'campus' => $campusScope ?? ($line['campus'] ?? 'morogoro_main'),
                'sex' => in_array($line['sex'] ?? null, ['male', 'female'], true) ? $line['sex'] : null,
                'faculty' => $line['faculty'] ?? null,
                'department' => $line['department'] ?? null,
                'program' => $line['program'] ?? null,
                'level' => $line['level'],
                'password' => Hash::make($plainPassword),
                'role' => 'cr',
            ]);

            dispatch(function () use ($user, $plainPassword) {
                Mail::to($user->email)->send(new CrCredentialsMail($user, $plainPassword));
            })->afterResponse();

            $created[] = [
                'name' => $user->name,
                'reg_no' => $user->reg_no,
                'email' => $user->email,
            ];
        }

        fclose($handle);

        return response()->json([
            'message' => count($created).' CR(s) added (passwords sent by email), '.count($skipped).' skipped.',
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Admin edits a CR: name, reg_no, phone number, password, faculty,
     * department, program, level, year_of_study. If the name or reg_no
     * changes (and a reg_no is present either way), the email is
     * regenerated automatically. Email/password changes are sent to the CR
     * at their email (the new one, if it changed).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless(in_array($user->role, ['cr', 'staff'], true), 404);

        $campusScope = $request->user()->campusScope();
        $label = $user->role === 'staff' ? 'Staff' : 'CRs';
        abort_if($campusScope && $user->campus !== $campusScope, 403, "You can only manage {$label} from your own campus.");

        if ($user->role === 'staff') {
            return $this->updateStaff($request, $user, $campusScope);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'reg_no' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('users', 'reg_no')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['sometimes', 'string', 'max:20'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'campus' => ['sometimes', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'sex' => ['sometimes', Rule::in(['male', 'female'])],
            'faculty' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'program' => ['sometimes', 'string', 'max:255'],
            'level' => ['sometimes', Rule::in(self::VALID_LEVELS)],
            'year_of_study' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // A regular Admin cannot move a CR to another campus.
        if ($campusScope) {
            unset($data['campus']);
        }

        $oldEmail = $user->email;
        $newPassword = null;

        if (array_key_exists('password', $data) && ! empty($data['password'])) {
            $newPassword = $data['password'];
            $data['password'] = Hash::make($newPassword);
        } else {
            unset($data['password']);
        }

        $nameChanging = isset($data['name']) && $data['name'] !== $user->name;
        $regNoChanging = array_key_exists('reg_no', $data) && $data['reg_no'] !== $user->reg_no;
        $effectiveName = $data['name'] ?? $user->name;
        $effectiveRegNo = array_key_exists('reg_no', $data) ? $data['reg_no'] : $user->reg_no;

        if (($nameChanging || $regNoChanging) && $effectiveRegNo) {
            try {
                $generated = CrEmailGenerator::generate($effectiveName, $effectiveRegNo);
                $candidate = $generated['email'];

                if ($candidate !== $user->email) {
                    $data['email'] = $this->resolveUniqueEmail($candidate, ignoreUserId: $user->id);
                }
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['reg_no' => $e->getMessage()]);
            }
        }

        $user->update($data);

        $emailChanged = isset($data['email']) && $data['email'] !== $oldEmail;

        if ($emailChanged || $newPassword) {
            $passwordForEmail = $newPassword ?? '(unchanged - keep using your existing password)';
            dispatch(function () use ($user, $passwordForEmail) {
                Mail::to($user->email)->send(new CrCredentialsMail($user, $passwordForEmail));
            })->afterResponse();
        }

        ActivityLog::record($request->user()->id, 'cr_updated', "{$request->user()->name} updated CR {$user->name}'s details.");

        return response()->json([
            'user' => $user,
            'message' => $emailChanged
                ? "Details saved. New email: {$user->email} (notification sent)."
                : 'CR details saved.',
        ]);
    }

    /**
     * Admin edits a Staff member: name, staff_id, position, phone, email,
     * password. No password/email is mailed - Staff already have their
     * password from registration, and email/password changes here are rare
     * corrections, not a fresh account.
     */
    private function updateStaff(Request $request, User $user, ?string $campusScope): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'staff_id' => ['sometimes', 'string', 'max:50', Rule::unique('users', 'staff_id')->ignore($user->id)->whereNull('deleted_at')],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'campus' => ['sometimes', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($campusScope) {
            unset($data['campus']);
        }

        if (array_key_exists('password', $data) && ! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record($request->user()->id, 'staff_updated', "{$request->user()->name} updated Staff {$user->name}'s details.");

        return response()->json(['user' => $user, 'message' => 'Staff details saved.']);
    }

    /**
     * Admin removes a CR. Their personal details (name, email, phone,
     * reg_no, faculty, department, program) are erased/hidden, BUT their
     * record is kept (their bookings and activity logs) so the history
     * shows what a "Deleted CR" did in the system - we don't fully (hard)
     * delete because that would break the booking/log history linked to them.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless(in_array($user->role, ['cr', 'staff'], true), 404);

        $isStaff = $user->role === 'staff';
        $label = $isStaff ? 'Staff' : 'CR';
        $campusScope = $request->user()->campusScope();
        abort_if($campusScope && $user->campus !== $campusScope, 403, "You can only manage {$label} from your own campus.");

        $originalName = $user->name;
        $placeholder = "Deleted {$label} #{$user->id}";

        $user->tokens()->delete();

        $user->update([
            'name' => $placeholder,
            'reg_no' => null,
            'staff_id' => null,
            'position' => null,
            'email' => "deleted-{$user->id}@deleted.local",
            'password' => Hash::make(Str::random(32)),
            'phone' => null,
            'sex' => null,
            'faculty' => null,
            'department' => null,
            'program' => null,
            'level' => null,
            'year_of_study' => null,
            'preferred_color' => null,
            'is_active' => false,
        ]);

        // Soft delete (deleted_at) - the user no longer appears in the
        // /admin/users list, but their record stays in the DB (their
        // bookings/logs remain accessible for history, via withTrashed()
        // on the relevant relations).
        $user->delete();

        ActivityLog::record(
            $request->user()->id,
            'user_deleted',
            "Admin removed {$label} \"{$originalName}\" (#{$user->id}). Their booking history has been preserved."
        );

        return response()->json(['message' => "{$label} removed. Their booking history has been preserved for records."]);
    }

    /**
     * Admin approves a pending CR/Staff self-registration - the account
     * becomes active and can now log in.
     */
    public function approve(Request $request, User $user): JsonResponse
    {
        abort_unless(in_array($user->role, ['cr', 'staff'], true), 404);

        $label = $user->role === 'staff' ? 'Staff' : 'CR';
        $campusScope = $request->user()->campusScope();
        abort_if($campusScope && $user->campus !== $campusScope, 403, "You can only manage {$label} from your own campus.");

        $user->update(['is_active' => true, 'approved_at' => now()]);

        ActivityLog::record($request->user()->id, 'user_approved', "{$request->user()->name} approved {$label} account {$user->name}.");

        return response()->json(['user' => $user, 'message' => "{$label} account approved. They can now log in."]);
    }

    /**
     * Admin rejects a still-pending CR/Staff self-registration. Unlike
     * destroy() (which soft-deletes and anonymizes an already-active
     * account so its booking history survives), a rejected registration
     * never had any activity worth preserving - so this hard-deletes the
     * row entirely, to avoid leaving clutter behind.
     */
    public function reject(Request $request, User $user): JsonResponse
    {
        abort_unless(in_array($user->role, ['cr', 'staff'], true), 404);
        abort_unless(! $user->is_active && ! $user->approved_at, 422, 'This account is not pending approval.');

        $label = $user->role === 'staff' ? 'Staff' : 'CR';
        $campusScope = $request->user()->campusScope();
        abort_if($campusScope && $user->campus !== $campusScope, 403, "You can only manage {$label} from your own campus.");

        $name = $user->name;

        ActivityLog::record($request->user()->id, 'user_rejected', "{$request->user()->name} rejected the pending {$label} registration for \"{$name}\".");

        $user->tokens()->delete();
        $user->forceDelete();

        return response()->json(['message' => "{$label} registration for \"{$name}\" rejected and removed."]);
    }

    /**
     * If the generated email already exists, append a number to the end of
     * the local-part until an unused email is found.
     */
    private function resolveUniqueEmail(string $email, ?int $ignoreUserId = null): string
    {
        $exists = fn (string $e) => User::where('email', $e)->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))->exists();

        if (! $exists($email)) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        // Use "." before the disambiguating number (e.g. "dickson.thomas25.2@...")
        // instead of concatenating it directly (which would produce
        // "dickson.thomas252@..." - which looks like an entirely different
        // year, not a disambiguating number).
        for ($i = 2; $i < 100; $i++) {
            $candidate = "{$local}.{$i}@{$domain}";
            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['reg_no' => 'Failed to generate a unique email. Contact the Admin.']);
    }
}
