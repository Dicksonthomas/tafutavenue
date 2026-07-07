<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Visibility:
     * - Super Admin's actions: hakuna anayeziona isipokuwa Super Admin mwenyewe.
     * - Admin wa kawaida: logs zake zinaonekana kwa Admin wote (super au la),
     *   lakini SIYO kwa CR/watumiaji.
     * - CR: logs zake zinaonekana kikamilifu kwa Admin wote. Kwa CR wenzake,
     *   zinaonekana TU kama zinahusiana na booking (booking_id ipo); kila CR
     *   anaona logs zake mwenyewe kwa ukamilifu bila kujali aina.
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        $query = ActivityLog::with(['user:id,name,role,is_super_admin', 'booking:id,venue_id,booking_date,start_time,end_time']);

        if ($viewer->isAdmin()) {
            $query->where(function ($q) use ($viewer) {
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
        } else {
            $query->where(function ($q) use ($viewer) {
                $q->where('user_id', $viewer->id)
                    ->orWhere(function ($q2) use ($viewer) {
                        $q2->whereNotNull('booking_id')
                            ->whereHas('user', fn ($u) => $u->where('role', '!=', 'admin'));
                    });
            });
        }

        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($logs);
    }
}
