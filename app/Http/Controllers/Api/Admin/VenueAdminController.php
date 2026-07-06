<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TimetableSlot;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VenueAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $venues = Venue::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('building', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->paginate(30);

        return response()->json($venues);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:venues,code'],
            'building' => ['nullable', 'string', 'max:255'],
            'faculty' => ['nullable', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:0'],
            'type' => ['required', Rule::in(['lecture_hall', 'laboratory', 'seminar_room', 'hall', 'other'])],
            'description' => ['nullable', 'string'],
            'blocked_purposes' => ['nullable', 'array'],
            'blocked_purposes.*' => [Rule::in(['study_unit', 'test', 'makeup_class', 'meeting', 'other'])],
            'restricted_levels' => ['nullable', 'array'],
            'restricted_levels.*' => [Rule::in(['Certificate', 'Diploma', 'Degree', 'Masters', 'PhD'])],
            'restricted_department' => ['nullable', 'string', 'max:255'],
        ]);

        $venue = Venue::create([
            ...$data,
            'status' => 'available',
            'source' => 'manual',
            'created_by' => $request->user()->id,
        ]);

        return response()->json($venue, 201);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('venues', 'code')->ignore($venue->id)],
            'building' => ['sometimes', 'nullable', 'string', 'max:255'],
            'faculty' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:0'],
            'type' => ['sometimes', Rule::in(['lecture_hall', 'laboratory', 'seminar_room', 'hall', 'other'])],
            'status' => ['sometimes', Rule::in(['available', 'maintenance', 'disabled'])],
            'description' => ['sometimes', 'nullable', 'string'],
            'blocked_purposes' => ['sometimes', 'nullable', 'array'],
            'blocked_purposes.*' => [Rule::in(['study_unit', 'test', 'makeup_class', 'meeting', 'other'])],
            'restricted_levels' => ['sometimes', 'nullable', 'array'],
            'restricted_levels.*' => [Rule::in(['Certificate', 'Diploma', 'Degree', 'Masters', 'PhD'])],
            'restricted_department' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $venue->update($data);

        return response()->json($venue);
    }

    public function destroy(Venue $venue): JsonResponse
    {
        $venue->delete();

        return response()->json(['message' => 'Venue imefutwa.']);
    }

    /**
     * Angalia kama tayari kuna ratiba (timetable slots) kwa semester hii, ili Admin
     * aambiwe kabla ya kuingiza: 'Update iliyopo' (futa za zamani kwanza) au 'Ongeza mpya'.
     */
    public function timetableStatus(Request $request): JsonResponse
    {
        $request->validate(['semester_id' => ['required', 'exists:semesters,id']]);

        $count = TimetableSlot::where('semester_id', $request->integer('semester_id'))->count();

        return response()->json(['existing_slots' => $count]);
    }

    /**
     * Ingiza ratiba rasmi ya mihadhara (kwa mfano kutoka mutimetable.mzumbe.ac.tz) kupitia
     * faili la CSV lenye columns: day_of_week,start_time,end_time,venue_name,venue_code,
     * building,capacity,course_unit,lecturer_name,program
     *
     * 'mode' => 'replace' hufuta ratiba iliyopo ya semester hii kabla ya kuingiza mpya;
     * 'add' (default) huongeza bila kufuta zilizopo (zinazofanana zinarukwa).
     * Venue mpya zisizokuwepo zitaundwa kiotomatiki (source=timetable_import).
     */
    public function importTimetable(Request $request): JsonResponse
    {
        $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'mode' => ['nullable', 'in:add,replace'],
        ]);

        if ($request->input('mode') === 'replace') {
            TimetableSlot::where('semester_id', $request->integer('semester_id'))->delete();
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $created = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $line = array_combine($header, $row);

            $venue = Venue::firstOrCreate(
                ['code' => $line['venue_code'] ?: null, 'name' => $line['venue_name']],
                [
                    'building' => $line['building'] ?? null,
                    'capacity' => $line['capacity'] ?? 0,
                    'type' => 'lecture_hall',
                    'status' => 'available',
                    'source' => 'timetable_import',
                ]
            );

            $exists = TimetableSlot::where('venue_id', $venue->id)
                ->where('semester_id', $request->integer('semester_id'))
                ->where('day_of_week', $line['day_of_week'])
                ->where('start_time', $line['start_time'])
                ->where('end_time', $line['end_time'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            TimetableSlot::create([
                'venue_id' => $venue->id,
                'semester_id' => $request->integer('semester_id'),
                'day_of_week' => $line['day_of_week'],
                'start_time' => $line['start_time'],
                'end_time' => $line['end_time'],
                'course_unit' => $line['course_unit'] ?? null,
                'lecturer_name' => $line['lecturer_name'] ?? null,
                'program' => $line['program'] ?? null,
                'source' => 'mzumbe_timetable_import',
            ]);
            $created++;
        }

        fclose($handle);

        return response()->json([
            'message' => "Timetable imeingizwa: {$created} zimeundwa, {$skipped} zilirukwa (tayari zipo).",
        ]);
    }
}
