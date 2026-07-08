<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableSlotController extends Controller
{
    /**
     * The CR types a Lecturer's name and gets their full weekly schedule,
     * so they can know where and when that Lecturer teaches without hassle.
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
