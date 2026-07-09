<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ReferenceDataController;
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
     * Admin pastes the link for the relevant semester from
     * mutimetable.mzumbe.ac.tz (e.g.
     * https://mutimetable.mzumbe.ac.tz/timetables/teaching/semestertwo_2025_2026_all_programmes/)
     * and the system pulls in venues + the schedule (course/lecturer/time)
     * automatically.
     *
     * 'mode' => 'replace' first deletes the existing timetable for this
     * semester (so it doesn't mix with the new one); 'add' (default) just
     * adds without deleting what already exists.
     */
    public function importFromLink(Request $request, MzumbeTimetableScraper $scraper): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'url' => ['required', 'url'],
            'mode' => ['nullable', 'in:add,replace'],
        ]);

        $campusScope = $request->user()->campusScope();
        abort_if($campusScope && $campusScope !== $data['campus'], 403, 'You can only work with your own campus.');

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

        ReferenceDataController::forgetProgramsCache();

        $message = "Timetable imported: {$result['venues']} venues, {$result['slots_created']} new timetable slots.";

        if (! empty($result['failed'])) {
            $message .= ' Venues that failed: '.implode(', ', $result['failed']).'.';
        }

        return response()->json([
            'message' => $message,
            ...$result,
        ]);
    }
}
