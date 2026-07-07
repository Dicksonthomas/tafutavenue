<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use App\Models\TimetableSlot;
use App\Services\MzumbeTimetableScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimetableImportController extends Controller
{
    /**
     * Admin anabandika link ya semester husika kutoka mutimetable.mzumbe.ac.tz
     * (mfano https://mutimetable.mzumbe.ac.tz/timetables/teaching/semestertwo_2025_2026_all_programmes/)
     * na mfumo unavuta venues + ratiba (course/lecturer/muda) moja kwa moja.
     *
     * 'mode' => 'replace' hufuta ratiba iliyopo ya semester hii kwanza (ili isijichanganye
     * na ile mpya); 'add' (default) huongeza tu bila kufuta zilizopo.
     */
    public function importFromLink(Request $request, MzumbeTimetableScraper $scraper): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'url' => ['required', 'url'],
            'mode' => ['nullable', 'in:add,replace'],
        ]);

        $semester = Semester::findOrFail($data['semester_id']);

        if (($data['mode'] ?? 'add') === 'replace') {
            TimetableSlot::where('semester_id', $semester->id)
                ->whereHas('venue', fn ($q) => $q->where('campus', $data['campus']))
                ->delete();
        }

        try {
            $result = $scraper->importFromUrl($data['url'], $semester, $data['campus']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = "Timetable imevutwa: venues {$result['venues']}, timetable slots mpya {$result['slots_created']}.";

        if (! empty($result['failed'])) {
            $message .= ' Venues zilizoshindikana: '.implode(', ', $result['failed']).'.';
        }

        return response()->json([
            'message' => $message,
            ...$result,
        ]);
    }
}
