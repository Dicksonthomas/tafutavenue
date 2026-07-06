<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * CR anaona logs zake tu; Admin anaona logs zote.
     */
    public function index(Request $request): JsonResponse
    {
        $logs = ActivityLog::with(['user:id,name', 'booking:id,venue_id,booking_date,start_time,end_time'])
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($logs);
    }
}
