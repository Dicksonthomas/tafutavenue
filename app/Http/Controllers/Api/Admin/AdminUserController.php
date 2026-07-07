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
    /**
     * Orodha ya Admin wote (kwa ajili ya Admin mkuu kusimamia wenzake).
     * Admin wa kawaida haoni Admin Mkuu (Super Admin) kwenye orodha hii.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            User::where('role', 'admin')
                ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('is_super_admin', false))
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Ongeza Admin mpya. Admin mpya HAWEZI kuwa Super Admin - hilo ni kwa
     * yule Admin wa kwanza aliyewekwa na mfumo pekee.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $admin = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
        ]);

        ActivityLog::record($request->user()->id, 'admin_created', "{$request->user()->name} added a new admin: {$admin->name}.");

        return response()->json([
            'user' => $admin,
            'message' => 'Admin mpya ameongezwa.',
        ], 201);
    }

    /**
     * Hariri taarifa za Admin - Super Admin pekee ndiye anaweza kuhariri
     * (kuepuka Admin mmoja kubadili password ya mwenzake).
     */
    public function update(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Huwezi kuhariri taarifa za Admin.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($admin->id)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $admin->update($data);

        ActivityLog::record($request->user()->id, 'admin_updated', "{$request->user()->name} updated admin {$admin->name}'s details.");

        return response()->json([
            'user' => $admin,
            'message' => 'Taarifa za Admin zimehifadhiwa.',
        ]);
    }

    /**
     * Futa Admin - Super Admin (Admin mkuu) hawezi kufutwa kabisa.
     */
    public function destroy(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_if($admin->isSuperAdmin(), 403, 'Huwezi kumfuta Admin Mkuu.');

        $admin->tokens()->delete();
        $adminName = $admin->name;
        $admin->delete();

        ActivityLog::record($request->user()->id, 'admin_removed', "{$request->user()->name} removed admin {$adminName}.");

        return response()->json(['message' => 'Admin amefutwa.']);
    }
}
