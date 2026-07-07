<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CrCredentialsMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\CrEmailGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
     * Orodha ya CR wote (kwa ajili ya Admin kuona/kutafuta).
     */
    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $users = User::query()
            ->where('role', 'cr')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('reg_no', 'like', "%{$q}%")
                        ->orWhere('program', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Admin anasajili CR mmoja mmoja. Ukitoa 'reg_no', email hutengenezwa
     * kiotomatiki (kama kwenye usajili wa kawaida). Kama Reg No ina mwaka wa
     * nyuma sana (chini ya 2022) au CR hana Reg No sahihi, Admin anaweza
     * kuacha 'reg_no' wazi na kuweka 'email' yake mwenyewe (njia mbadala).
     * Password hutengenezwa kiotomatiki na kutumwa kwenye email - haionekani
     * kwenye response hii.
     */
    public function store(Request $request): JsonResponse
    {
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

        if (empty($data['reg_no']) && empty($data['email'])) {
            throw ValidationException::withMessages([
                'email' => 'Weka Reg No (kutengeneza email kiotomatiki) au email moja kwa moja.',
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

        Mail::to($user->email)->queue(new CrCredentialsMail($user, $plainPassword));

        ActivityLog::record($request->user()->id, 'cr_created', "{$request->user()->name} registered a new CR: {$user->name}.");

        return response()->json([
            'user' => $user,
            'message' => "CR ameongezwa. Password imetumwa kwenye email: {$user->email}",
        ], 201);
    }

    /**
     * Pakua faili la mfano (template) la CSV kwa ajili ya kuingiza CR wengi kwa mkupuo.
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
     * Ingiza CR wengi kwa mkupuo kutoka faili la CSV lenye columns:
     * name,reg_no,phone,faculty,department,program,level
     *
     * Email hutengenezwa kiotomatiki kutoka name+reg_no kwa kila mstari.
     * Reg No zenye mwaka chini ya 2022 zinarukwa (Admin awasiliane na CR husika).
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        $created = [];
        $skipped = [];

        while (($row = fgetcsv($handle)) !== false) {
            $line = array_combine($header, $row);
            $label = $line['reg_no'] ?? ($line['name'] ?? '(bila jina)');

            if (empty($line['reg_no']) || User::where('reg_no', $line['reg_no'])->exists()) {
                $skipped[] = "{$label} (reg_no tupu au tayari ipo)";

                continue;
            }

            if (! in_array($line['level'], self::VALID_LEVELS, true)) {
                $skipped[] = "{$label} (level batili)";

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
                'campus' => $line['campus'] ?? 'morogoro_main',
                'sex' => in_array($line['sex'] ?? null, ['male', 'female'], true) ? $line['sex'] : null,
                'faculty' => $line['faculty'] ?? null,
                'department' => $line['department'] ?? null,
                'program' => $line['program'] ?? null,
                'level' => $line['level'],
                'password' => Hash::make($plainPassword),
                'role' => 'cr',
            ]);

            Mail::to($user->email)->queue(new CrCredentialsMail($user, $plainPassword));

            $created[] = [
                'name' => $user->name,
                'reg_no' => $user->reg_no,
                'email' => $user->email,
            ];
        }

        fclose($handle);

        return response()->json([
            'message' => count($created).' CR wameongezwa (password zimetumwa kwa email), '.count($skipped).' wamerukwa.',
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Admin anahariri CR: jina, namba ya simu, password, faculty, department,
     * program, level, year_of_study. Jina likibadilika (na CR ana reg_no),
     * email hutengenezwa upya kiotomatiki. Mabadiliko ya email/password
     * hutumwa kwa CR husika kwenye email yake (mpya ikiwa imebadilika).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'cr', 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
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

        $oldEmail = $user->email;
        $newPassword = null;

        if (array_key_exists('password', $data) && ! empty($data['password'])) {
            $newPassword = $data['password'];
            $data['password'] = Hash::make($newPassword);
        } else {
            unset($data['password']);
        }

        $nameChanging = isset($data['name']) && $data['name'] !== $user->name;

        if ($nameChanging && $user->reg_no) {
            try {
                $generated = CrEmailGenerator::generate($data['name'], $user->reg_no);
                $candidate = $generated['email'];

                if ($candidate !== $user->email) {
                    $data['email'] = $this->resolveUniqueEmail($candidate, ignoreUserId: $user->id);
                }
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['name' => $e->getMessage()]);
            }
        }

        $user->update($data);

        $emailChanged = isset($data['email']) && $data['email'] !== $oldEmail;

        if ($emailChanged || $newPassword) {
            $passwordForEmail = $newPassword ?? '(hujabadilisha - tumia password uliyokuwa nayo)';
            Mail::to($user->email)->queue(new CrCredentialsMail($user, $passwordForEmail));
        }

        ActivityLog::record($request->user()->id, 'cr_updated', "{$request->user()->name} updated CR {$user->name}'s details.");

        return response()->json([
            'user' => $user,
            'message' => $emailChanged
                ? "Taarifa zimehifadhiwa. Email mpya: {$user->email} (arifa imetumwa)."
                : 'Taarifa za CR zimehifadhiwa.',
        ]);
    }

    /**
     * Admin anafuta CR. Taarifa zake binafsi (jina, email, simu, reg_no, faculty,
     * department, program) zinafutwa/zinafichwa, LAKINI rekodi yake inabaki
     * (bookings na activity logs zake) ili history ionekane kuwa "CR Aliyefutwa"
     * aliwahi kufanya nini kwenye mfumo - hatufuti kabisa (hard delete) kwa
     * kuwa hiyo ingevunja historia ya bookings/logs zilizounganishwa naye.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'cr', 404);

        $originalName = $user->name;
        $placeholder = "CR Aliyefutwa #{$user->id}";

        $user->tokens()->delete();

        $user->update([
            'name' => $placeholder,
            'reg_no' => null,
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

        // Soft delete (deleted_at) - CR haonekani tena kwenye orodha ya /admin/users,
        // lakini rekodi yake inabaki DB (bookings/logs zake bado zinapatikana kwa
        // historia, kupitia withTrashed() kwenye relations husika).
        $user->delete();

        ActivityLog::record(
            $request->user()->id,
            'user_deleted',
            "Admin amemfuta CR \"{$originalName}\" (#{$user->id}). Historia ya bookings zake imehifadhiwa."
        );

        return response()->json(['message' => 'CR amefutwa. Historia yake ya bookings imehifadhiwa kwa rekodi.']);
    }

    /**
     * Kama email iliyotengenezwa tayari ipo, ongeza namba mwishoni mwa
     * local-part mpaka ipatikane email isiyotumika.
     */
    private function resolveUniqueEmail(string $email, ?int $ignoreUserId = null): string
    {
        $exists = fn (string $e) => User::where('email', $e)->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))->exists();

        if (! $exists($email)) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        // Tumia "." kabla ya namba ya kutofautisha (mfano "dickson.thomas25.2@...")
        // badala ya kuunganisha moja kwa moja (ambayo ingezalisha "dickson.thomas252@..."
        // - inayoonekana kama mwaka tofauti kabisa, si kama namba ya kutofautisha).
        for ($i = 2; $i < 100; $i++) {
            $candidate = "{$local}.{$i}@{$domain}";
            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['reg_no' => 'Imeshindikana kutengeneza email ya kipekee. Wasiliana na Admin.']);
    }
}
