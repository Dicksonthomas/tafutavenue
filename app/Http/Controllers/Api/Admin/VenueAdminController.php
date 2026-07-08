<?php

namespace App\Http\Controllers\Api\Admin;

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
     * Admin wa kawaida (siyo Super Admin) anaweza tu kufanya kazi na campus
     * yake mwenyewe - 403 akijaribu campus nyingine.
     */
    private function assertCampusAllowed(Request $request, string $campus): void
    {
        $scope = $request->user()->campusScope();
        abort_if($scope && $scope !== $campus, 403, 'Unaweza kufanya kazi na campus yako pekee.');
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
            // Admin wa kawaida amefungiwa campus yake pekee; Super Admin
            // anaweza kuchuja kwa campus yoyote (au zote).
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

        // Admin wa kawaida hawezi kuongeza venue kwa campus tofauti na yake
        // mwenyewe - tunailazimisha (siyo tu kuikataa) ili fomu isivunjike.
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

        // Admin wa kawaida hawezi kuihamisha venue kwenda campus nyingine.
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

        return response()->json(['message' => 'Venue imefutwa.']);
    }

    /**
     * Angalia kama tayari kuna ratiba (timetable slots) kwa semester hii, ili Admin
     * aambiwe kabla ya kuingiza: 'Update iliyopo' (futa za zamani kwanza) au 'Ongeza mpya'.
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
     * Futa kabisa ratiba (timetable slots) ya campus+semester husika, kupitia
     * yote iliyoingizwa kwa link au CSV - kwa ajili ya kusafisha data mbovu
     * kabla ya kuingiza upya. Venues zilizoundwa na import (source=timetable_import)
     * pia zinafutwa, ISIPOKUWA zile zenye bookings za CR (hizo zinabaki ili
     * historia ya bookings isivunjike).
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

        // Venue moja inaweza kuwa na timetable_slots za semester NYINGINE
        // (zisizohusika na kufuta huku) au bookings za CR - hizo ndizo pekee
        // zinazoifanya isiwe salama kuifuta. Bila ukaguzi huu, venue ambayo
        // bado inatumika kwenye semester nyingine ingefutwa kimakosa.
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

        $message = "Timetable data imefutwa: schedule entries {$slotsDeleted}, venues {$venuesDeleted}.";

        if ($venuesKept > 0) {
            $message .= " Venues {$venuesKept} zimebaki kwa sababu bado zina ratiba kwenye semester nyingine au zina bookings za CR.";
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

        return response()->json([
            'message' => "Timetable imeingizwa: {$created} zimeundwa, {$skipped} zilirukwa (tayari zipo).",
        ]);
    }
}
