<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Route hii ni Admin PEKEE (imelindwa na middleware 'admin') - CR hawana
     * ufikiaji wa logs kabisa.
     *
     * Visibility kwa Admin walioingia:
     * - Logs za CR: zinaonekana kwa Admin wote.
     * - Logs za Admin wa kawaida: zinaonekana kwa Admin wote (super au la).
     * - Logs za Super Admin: zinaonekana kwa Super Admin mwenyewe TU, hazionekani
     *   kwa Admin wengine.
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        $query = ActivityLog::with(['user:id,name,role,is_super_admin', 'booking:id,venue_id,booking_date,start_time,end_time'])
            ->where(function ($q) use ($viewer) {
                $q->whereHas('user', function ($u) {
                    $u->where('role', '!=', 'admin')
                        ->orWhere(function ($u2) {
                            $u2->where('role', 'admin')->where('is_super_admin', false);
                        });
                });

                if ($viewer->isSuperAdmin()) {
                    $q->orWhere('user_id', $viewer->id);
                }
            });

        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($logs);
    }
}
