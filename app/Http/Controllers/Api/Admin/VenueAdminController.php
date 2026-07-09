<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\TimetableSlot;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VenueAdminController extends Controller
{
    /**
     * A regular Admin (not a Super Admin) can only work with their own
     * campus - 403 if they try another campus.
     */
    private function assertCampusAllowed(Request $request, string $campus): void
    {
        $scope = $request->user()->campusScope();
        abort_if($scope && $scope !== $campus, 403, 'You can only work with your own campus.');
    }

    public function index(Request $request): JsonResponse
    {
        $campusScope = $request->user()->campusScope();

        $venues = Venue::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('building', 'like', "%{$q}%");
                });
            })
            // A regular Admin is confined to their own campus; a Super Admin
            // can filter by any campus (or none, i.e. all of them).
            ->when($campusScope, fn ($query) => $query->where('campus', $campusScope))
            ->when(! $campusScope && $request->filled('campus'), fn ($query) => $query->where('campus', $request->string('campus')))
            ->orderBy('name')
            ->paginate(30);

        return response()->json($venues);
    }

    public function store(Request $request): JsonResponse
    {
        $campusScope = $request->user()->campusScope();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:venues,code'],
            'building' => ['nullable', 'string', 'max:255'],
            'faculty' => ['nullable', 'string', 'max:255'],
            'campus' => [$campusScope ? 'nullable' : 'required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'capacity' => ['required', 'integer', 'min:0'],
            'type' => ['required', Rule::in(['lecture_hall', 'laboratory', 'seminar_room', 'hall', 'other'])],
            'description' => ['nullable', 'string'],
            'blocked_purposes' => ['nullable', 'array'],
            'blocked_purposes.*' => [Rule::in(['study_unit', 'test', 'makeup_class', 'meeting', 'other'])],
            'restricted_levels' => ['nullable', 'array'],
            'restricted_levels.*' => [Rule::in(['Certificate', 'Diploma', 'Degree', 'Masters', 'PhD'])],
            'restricted_department' => ['nullable', 'string', 'max:255'],
        ]);

        // A regular Admin cannot add a venue for a campus other than their
        // own - we force it (not just reject it) so the form doesn't break.
        if ($campusScope) {
            $data['campus'] = $campusScope;
        }

        $venue = Venue::create([
            ...$data,
            'status' => 'available',
            'source' => 'manual',
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->user()->id, 'venue_created', "{$request->user()->name} added a new venue: {$venue->name}.");

        return response()->json($venue, 201);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $this->assertCampusAllowed($request, $venue->campus);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('venues', 'code')->ignore($venue->id)],
            'building' => ['sometimes', 'nullable', 'string', 'max:255'],
            'faculty' => ['sometimes', 'nullable', 'string', 'max:255'],
            'campus' => ['sometimes', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
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

        // A regular Admin cannot move a venue to another campus.
        if ($request->user()->campusScope()) {
            unset($data['campus']);
        }

        $venue->update($data);

        ActivityLog::record($request->user()->id, 'venue_updated', "{$request->user()->name} updated venue {$venue->name}.");

        return response()->json($venue);
    }

    public function destroy(Request $request, Venue $venue): JsonResponse
    {
        $this->assertCampusAllowed($request, $venue->campus);

        $venueName = $venue->name;
        $venue->delete();

        ActivityLog::record($request->user()->id, 'venue_deleted', "{$request->user()->name} deleted venue {$venueName}.");

        return response()->json(['message' => 'Venue deleted.']);
    }

    /**
     * Check whether timetable slots already exist for this semester, so the
     * Admin is asked before importing: "Update existing" (delete the old
     * ones first) or "Add new".
     */
    public function timetableStatus(Request $request): JsonResponse
    {
        $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'campus' => ['nullable', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
        ]);

        if ($request->filled('campus')) {
            $this->assertCampusAllowed($request, $request->string('campus')->toString());
        }

        $count = TimetableSlot::where('semester_id', $request->integer('semester_id'))
            ->when($request->filled('campus'), fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $request->string('campus'))))
            ->count();

        return response()->json(['existing_slots' => $count]);
    }

    /**
     * Permanently delete the timetable (timetable slots) for the given
     * campus+semester, however it was imported (link or CSV) - for cleaning
     * up bad data before re-importing. Venues created by the import
     * (source=timetable_import) are also deleted, EXCEPT those with CR
     * bookings (those are kept so booking history isn't broken).
     */
    public function clearTimetable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
        ]);

        $this->assertCampusAllowed($request, $data['campus']);

        $slotsDeleted = TimetableSlot::where('semester_id', $data['semester_id'])
            ->whereHas('venue', fn ($q) => $q->where('campus', $data['campus']))
            ->delete();

        // A venue can have timetable_slots for ANOTHER semester (unrelated to
        // this deletion) or CR bookings - those are the only things that make
        // it unsafe to delete. Without this check, a venue still in use in
        // another semester would be deleted by mistake.
        $candidateVenueIds = Venue::where('campus', $data['campus'])
            ->where('source', 'timetable_import')
            ->pluck('id');

        $venueIdsStillInUse = TimetableSlot::whereIn('venue_id', $candidateVenueIds)
            ->pluck('venue_id')
            ->unique()
            ->merge(
                Booking::whereIn('venue_id', $candidateVenueIds)->pluck('venue_id')->unique()
            )
            ->unique();

        $venuesDeleted = Venue::whereIn('id', $candidateVenueIds)
            ->whereNotIn('id', $venueIdsStillInUse)
            ->delete();

        $venuesKept = $candidateVenueIds->count() - $venuesDeleted;

        ReferenceDataController::forgetProgramsCache();

        $message = "Timetable data deleted: {$slotsDeleted} schedule entries, {$venuesDeleted} venues.";

        if ($venuesKept > 0) {
            $message .= " {$venuesKept} venue(s) were kept because they still have a schedule in another semester or have CR bookings.";
        }

        ActivityLog::record(
            $request->user()->id,
            'timetable_cleared',
            "{$request->user()->name} cleared timetable data for {$data['campus']}: {$slotsDeleted} slots, {$venuesDeleted} venues removed."
        );

        return response()->json([
            'message' => $message,
            'slots_deleted' => $slotsDeleted,
            'venues_deleted' => $venuesDeleted,
            'venues_kept' => $venuesKept,
        ]);
    }

    /**
     * Import the official lecture timetable (e.g. from mutimetable.mzumbe.ac.tz)
     * via a CSV file with columns: day_of_week,start_time,end_time,venue_name,
     * venue_code,building,capacity,course_unit,lecturer_name,program
     *
     * 'mode' => 'replace' deletes the existing timetable for this semester
     * before importing the new one; 'add' (default) adds without deleting
     * existing entries (matching ones are skipped). New venues that don't
     * already exist are created automatically (source=timetable_import).
     */
    public function importTimetable(Request $request): JsonResponse
    {
        $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'campus' => ['required', Rule::in(['morogoro_main', 'dar_es_salaam', 'tanga', 'mbeya'])],
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'mode' => ['nullable', 'in:add,replace'],
        ]);

        $this->assertCampusAllowed($request, $request->string('campus')->toString());

        $campus = $request->string('campus')->toString();

        if ($request->input('mode') === 'replace') {
            TimetableSlot::where('semester_id', $request->integer('semester_id'))
                ->whereHas('venue', fn ($q) => $q->where('campus', $campus))
                ->delete();
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $created = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $line = array_combine($header, $row);

            $venue = Venue::firstOrCreate(
                ['code' => $line['venue_code'] ?: null, 'name' => $line['venue_name'], 'campus' => $campus],
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

        ReferenceDataController::forgetProgramsCache();

        return response()->json([
            'message' => "Timetable imported: {$created} created, {$skipped} skipped (already exist).",
        ]);
    }
}
