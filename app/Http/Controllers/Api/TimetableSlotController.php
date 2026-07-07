<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableSlotController extends Controller
{
    /**
     * CR anaandika jina la Lecturer na kupata ratiba yake yote ya wiki,
     * ili aweze kujua Lecturer wake anafundisha wapi na lini bila kuhangaika.
     */
    public function byLecturer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2'],
        ]);

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $slots = TimetableSlot::with('venue')
            ->whereHas('venue', fn ($q) => $q->where('campus', $request->user()->campus))
            ->where('lecturer_name', 'like', '%'.$data['name'].'%')
            ->get()
            ->sortBy([
                fn ($a, $b) => array_search($a->day_of_week, $days) <=> array_search($b->day_of_week, $days),
                fn ($a, $b) => strcmp($a->start_time, $b->start_time),
            ])
            ->values();

        return response()->json($slots);
    }
}
