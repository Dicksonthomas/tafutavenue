<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SystemController extends Controller
{
    /**
     * Run any pending database migrations. Exists because this app's hosting
     * (Railway) does not automatically run "php artisan migrate" on deploy,
     * and the Super Admin has no shell/CLI access to trigger it manually.
     * Super Admin only, since it directly changes the database schema.
     */
    public function migrate(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a Super Admin can run migrations.');

        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();

        return response()->json([
            'success' => $exitCode === 0,
            'output' => $output,
        ]);
    }
}
