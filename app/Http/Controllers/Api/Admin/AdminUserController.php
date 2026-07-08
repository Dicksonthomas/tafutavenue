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
     * List of all Admins. The Main Super Admin (is_main_super_admin) is not
     * visible to anyone except themselves - both regular Admins and promoted
     * Super Admins cannot see them.
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
     * Add a new Admin. Only a Super Admin (main or promoted) can add an
     * Admin. "is_super_admin" during creation is only allowed if the creator
     * is the Main Super Admin - otherwise it is ignored (the new Admin stays
     * a regular admin). Campus is required for a regular Admin (not super).
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a Super Admin can add an Admin.');

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
            'message' => 'New admin added.',
        ], 201);
    }

    /**
     * Edit an Admin's details - only a Super Admin (main or promoted) can
     * edit. No one can edit the Main Super Admin except themselves. Changing
     * "is_super_admin" status is for the Main Super Admin ONLY - a promoted
     * Super Admin cannot promote/demote another Admin.
     */
    public function update(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'You cannot edit Admin details.');
        abort_if(
            $admin->isMainSuperAdmin() && $admin->id !== $request->user()->id,
            403,
            'You cannot edit the Main Super Admin.'
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
            'message' => "Admin's details have been saved.",
        ]);
    }

    /**
     * Remove an Admin - the Main Super Admin can never be removed by anyone.
     * Promoted Super Admins can be removed by any Super Admin (main or
     * another promoted one).
     */
    public function destroy(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a Super Admin can remove an Admin.');
        abort_if($admin->isMainSuperAdmin(), 403, 'You cannot remove the Main Super Admin.');

        $admin->tokens()->delete();
        $adminName = $admin->name;
        $admin->delete();

        ActivityLog::record($request->user()->id, 'admin_removed', "{$request->user()->name} removed admin {$adminName}.");

        return response()->json(['message' => 'Admin removed.']);
    }
}
