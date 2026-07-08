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
     * Query of logs the $viewer is allowed to see (before any additional
     * filters like 'action').
     *
     * Visibility for logged-in Admins:
     * - Logs with no known actor (user_id null): always visible.
     * - CR logs: visible to all Admins.
     * - Regular Admin logs: visible to all Admins (super or not).
     * - Super Admin logs: visible ONLY to that Super Admin themselves.
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
     * This route is Admin ONLY (protected by the 'admin' middleware) - CRs
     * have no access to logs at all. Optional 'action' query param - filter
     * by a specific action type (e.g. 'login_success').
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
     * List of distinct "actions" that exist (for the filter dropdown),
     * restricted to logs visible to $viewer only.
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
     * Delete a SINGLE log - only allowed if that log is visible to $viewer
     * (a regular Admin cannot delete a Super Admin log they can't even see).
     */
    public function destroy(Request $request, ActivityLog $log): JsonResponse
    {
        abort_unless(
            $this->visibleQuery($request->user())->whereKey($log->id)->exists(),
            404
        );

        $log->delete();

        return response()->json(['message' => 'Log deleted.']);
    }

    /**
     * Delete ALL logs visible to $viewer, filtered by 'action' if provided
     * (otherwise every log visible to them is deleted).
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $count = $this->visibleQuery($request->user())
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->delete();

        return response()->json(['message' => "{$count} log(s) deleted.", 'deleted' => $count]);
    }
}
