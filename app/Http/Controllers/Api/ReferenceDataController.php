<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReferenceDataController extends Controller
{
    private const CAMPUSES = [
        ['value' => 'morogoro_main', 'label' => 'Morogoro Main'],
        ['value' => 'dar_es_salaam', 'label' => 'Dar es Salaam'],
        ['value' => 'tanga', 'label' => 'Tanga'],
        ['value' => 'mbeya', 'label' => 'Mbeya'],
    ];

    private const FACULTIES = [
        ['value' => 'FST', 'label' => 'FST - Faculty of Science and Technology'],
        ['value' => 'FOL', 'label' => 'FOL - Faculty of Law'],
        ['value' => 'SOPAM', 'label' => 'SOPAM - School of Public Administration and Management'],
        ['value' => 'SOB', 'label' => 'SOB - School of Business'],
        ['value' => 'IDS', 'label' => 'IDS - Institute of Development Studies'],
    ];

    private const DEPARTMENTS_BY_FACULTY = [
        'FST' => ['Computer Science', 'Information Technology', 'Mathematics and Statistics', 'Environmental Science'],
        'FOL' => ['Public Law', 'Private Law', 'Commercial Law'],
        'SOPAM' => ['Public Administration', 'Local Government Management', 'Human Resource Management'],
        'SOB' => ['Accounting', 'Finance', 'Marketing', 'Business Administration', 'Procurement and Logistics Management'],
        'IDS' => ['Development Studies', 'Rural Development', 'Community Development'],
    ];

    /** Level -> number of years of study. */
    private const YEARS_BY_LEVEL = [
        'Certificate' => 1,
        'Diploma' => 2,
        'Degree' => 3,
        'Masters' => 2,
        'PhD' => 2,
    ];

    public function campuses(): JsonResponse
    {
        return response()->json(self::CAMPUSES);
    }

    public function faculties(): JsonResponse
    {
        return response()->json(self::FACULTIES);
    }

    public function departments(): JsonResponse
    {
        return response()->json(self::DEPARTMENTS_BY_FACULTY);
    }

    public function levelYears(): JsonResponse
    {
        return response()->json(self::YEARS_BY_LEVEL);
    }

    /**
     * Real programs pulled from the university timetable (course scheduling
     * data), for the live-search dropdown during registration.
     *
     * This scans and regex-parses every distinct `program` value in
     * timetable_slots, which grows with every import - too expensive to redo
     * on every page load, so it's cached and only recomputed when the
     * timetable actually changes (see forgetProgramsCache(), called from the
     * timetable import/clear endpoints).
     */
    public function programs(Request $request): JsonResponse
    {
        $campus = $request->filled('campus') ? $request->string('campus')->toString() : null;

        $programs = Cache::remember(self::programsCacheKey($campus), now()->addHours(6), function () use ($campus) {
            return TimetableSlot::query()
                ->when($campus, fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $campus)))
                ->whereNotNull('program')
                ->where('program', '!=', '')
                ->distinct()
                ->pluck('program')
                ->flatMap(function ($p) {
                    // In the timetable, multiple programs sharing one course are
                    // stored joined by spaces (not commas), e.g.
                    // "BAF-BS 1A BAF-BS 1B BSc.ICTB 1" - each token is
                    // "CODE YEAR" (e.g. "BSc.ICTB 1" or "BAF-BS 1A").
                    preg_match_all('/[A-Za-z][A-Za-z.\-]*\s+\d+[A-Za-z]?/', $p, $matches);

                    return $matches[0] ?: [trim($p)];
                })
                ->map(fn ($p) => trim($p))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
        });

        return response()->json($programs);
    }

    private static function programsCacheKey(?string $campus): string
    {
        return 'reference:programs:'.($campus ?? 'all');
    }

    /**
     * Called after any timetable import/replace/clear so the next
     * programs() request recomputes instead of serving stale data.
     */
    public static function forgetProgramsCache(): void
    {
        Cache::forget(self::programsCacheKey(null));
        foreach (self::CAMPUSES as $campus) {
            Cache::forget(self::programsCacheKey($campus['value']));
        }
    }
}
