<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Query ya logs zinazoruhusiwa kuonekana na $viewer (kabla ya filters
     * nyingine za ziada kama 'action').
     *
     * Visibility kwa Admin walioingia:
     * - Logs zisizo na actor anayejulikana (user_id null): daima zinaonekana.
     * - Logs za CR: zinaonekana kwa Admin wote.
     * - Logs za Admin wa kawaida: zinaonekana kwa Admin wote (super au la).
     * - Logs za Super Admin: zinaonekana kwa Super Admin mwenyewe TU.
     */
    private function visibleQuery(User $viewer): Builder
    {
        return ActivityLog::where(function ($q) use ($viewer) {
            $q->whereNull('user_id')
                ->orWhereHas('user', function ($u) {
                    $u->where('role', '!=', 'admin')
                        ->orWhere(function ($u2) {
                            $u2->where('role', 'admin')->where('is_super_admin', false);
                        });
                });

            if ($viewer->isSuperAdmin()) {
                $q->orWhere('user_id', $viewer->id);
            }
        });
    }

    /**
     * Route hii ni Admin PEKEE (imelindwa na middleware 'admin') - CR hawana
     * ufikiaji wa logs kabisa. Query param 'action' (hiari) - chuja kwa aina
     * mahususi ya action (mfano 'login_success').
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->visibleQuery($request->user())
            ->with(['user:id,name,role,is_super_admin', 'booking:id,venue_id,booking_date,start_time,end_time'])
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')));

        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Orodha ya "actions" za kipekee zilizopo (kwa ajili ya dropdown ya filter),
     * ikizingatia logs zinazoonekana kwa $viewer pekee.
     */
    public function actions(Request $request): JsonResponse
    {
        $actions = $this->visibleQuery($request->user())
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return response()->json($actions);
    }

    /**
     * Futa log MOJA - inaruhusiwa tu kama log hiyo inaonekana kwa $viewer
     * (Admin wa kawaida hawezi kufuta log ya Super Admin asiyoiona kabisa).
     */
    public function destroy(Request $request, ActivityLog $log): JsonResponse
    {
        abort_unless(
            $this->visibleQuery($request->user())->whereKey($log->id)->exists(),
            404
        );

        $log->delete();

        return response()->json(['message' => 'Log imefutwa.']);
    }

    /**
     * Futa logs ZOTE zinazoonekana kwa $viewer, zikichujwa na 'action' kama
     * imetolewa (vinginevyo zote zinazoonekana kwake zinafutwa).
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $count = $this->visibleQuery($request->user())
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->delete();

        return response()->json(['message' => "Logs {$count} zimefutwa.", 'deleted' => $count]);
    }
}
