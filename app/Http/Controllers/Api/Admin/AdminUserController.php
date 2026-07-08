<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    private const CAMPUSES = ['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'];

    /**
     * Orodha ya Admin wote. Super Admin "Mkuu" (is_main_super_admin) haonekani
     * kwa mtu yeyote isipokuwa yeye mwenyewe - Admin wa kawaida na Super Admin
     * waliopandishwa (promoted) wote hawamuoni.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            User::where('role', 'admin')
                ->when(! $request->user()->isMainSuperAdmin(), fn ($q) => $q->where('is_main_super_admin', false))
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Ongeza Admin mpya. Super Admin PEKEE (mkuu au aliyepandishwa) ndiye
     * anayeweza kuongeza Admin. "is_super_admin" wakati wa kuongeza inaruhusiwa
     * TU kama muumbaji ni Super Admin Mkuu - vinginevyo inapuuzwa (Admin mpya
     * anabaki wa kawaida). Campus inahitajika kwa Admin wa kawaida (siyo super).
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Super Admin pekee anaweza kuongeza Admin.');

        $makeSuperAdmin = $request->boolean('is_super_admin') && $request->user()->isMainSuperAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8'],
            'campus' => [$makeSuperAdmin ? 'nullable' : 'required', Rule::in(self::CAMPUSES)],
        ]);

        $admin = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
            'campus' => $makeSuperAdmin ? null : $data['campus'],
        ]);
        $admin->is_super_admin = $makeSuperAdmin;
        $admin->save();

        ActivityLog::record($request->user()->id, 'admin_created', "{$request->user()->name} added a new admin: {$admin->name}.");

        return response()->json([
            'user' => $admin,
            'message' => 'Admin mpya ameongezwa.',
        ], 201);
    }

    /**
     * Hariri taarifa za Admin - Super Admin PEKEE (mkuu au aliyepandishwa)
     * ndiye anaweza kuhariri. Hakuna anayeweza kuhariri Super Admin Mkuu
     * isipokuwa yeye mwenyewe. Kubadilisha hadhi ya "is_super_admin" ni kwa
     * Super Admin Mkuu PEKEE - Super Admin aliyepandishwa hawezi kumpandisha/
     * kumshusha Admin mwingine.
     */
    public function update(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Huwezi kuhariri taarifa za Admin.');
        abort_if(
            $admin->isMainSuperAdmin() && $admin->id !== $request->user()->id,
            403,
            'Huwezi kuhariri Super Admin Mkuu.'
        );

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'campus' => ['sometimes', 'nullable', Rule::in(self::CAMPUSES)],
            'is_super_admin' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $canPromote = $request->user()->isMainSuperAdmin();

        if (array_key_exists('is_super_admin', $data)) {
            if ($canPromote) {
                $admin->is_super_admin = $data['is_super_admin'];
                if ($admin->is_super_admin) {
                    $data['campus'] = null;
                }
            }
            unset($data['is_super_admin']);
        }

        $admin->fill($data);
        $admin->save();

        ActivityLog::record($request->user()->id, 'admin_updated', "{$request->user()->name} updated admin {$admin->name}'s details.");

        return response()->json([
            'user' => $admin,
            'message' => 'Taarifa za Admin zimehifadhiwa.',
        ]);
    }

    /**
     * Futa Admin - Super Admin Mkuu hawezi kufutwa kabisa na mtu yeyote.
     * Super Admin waliopandishwa (promoted) wanaweza kufutwa na Super Admin
     * yeyote (mkuu au mwingine aliyepandishwa).
     */
    public function destroy(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Super Admin pekee anaweza kumfuta Admin.');
        abort_if($admin->isMainSuperAdmin(), 403, 'Huwezi kumfuta Super Admin Mkuu.');

        $admin->tokens()->delete();
        $adminName = $admin->name;
        $admin->delete();

        ActivityLog::record($request->user()->id, 'admin_removed', "{$request->user()->name} removed admin {$adminName}.");

        return response()->json(['message' => 'Admin amefutwa.']);
    }
}
