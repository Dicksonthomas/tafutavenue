<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * Orodha ya Admin wote (kwa ajili ya Admin mkuu kusimamia wenzake).
     */
    public function index(): JsonResponse
    {
        return response()->json(
            User::where('role', 'admin')->orderBy('name')->get()
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $admin = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
        ]);

        return response()->json([
            'user' => $admin,
            'message' => 'Admin mpya ameongezwa.',
        ], 201);
    }

    /**
     * Futa Admin - Super Admin (Admin mkuu) hawezi kufutwa kabisa.
     */
    public function destroy(User $admin): JsonResponse
    {
        abort_unless($admin->role === 'admin', 404);
        abort_if($admin->isSuperAdmin(), 403, 'Huwezi kumfuta Admin Mkuu.');

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['message' => 'Admin amefutwa.']);
    }
}
