<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** Level -> idadi ya miaka ya masomo. */
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
     * Programs halisi zilizovutwa kutoka kwenye timetable ya chuo (course scheduling data),
     * kwa ajili ya dropdown yenye live search kwenye usajili.
     */
    public function programs(Request $request): JsonResponse
    {
        $programs = TimetableSlot::query()
            ->when($request->filled('campus'), fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $request->string('campus'))))
            ->whereNotNull('program')
            ->where('program', '!=', '')
            ->distinct()
            ->orderBy('program')
            ->pluck('program')
            ->flatMap(fn ($p) => array_map('trim', explode(',', $p)))
            ->unique()
            ->sort()
            ->values();

        return response()->json($programs);
    }
}
