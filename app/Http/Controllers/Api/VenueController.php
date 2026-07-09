<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Semester;
use App\Models\TimetableSlot;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    /**
     * Quick overview of today - as soon as the CR logs in they see the
     * number of 'free' and 'booked' venues without choosing anything.
     */
    public function todayOverview(Request $request): JsonResponse
    {
        $campus = $request->user()->campus;
        $today = Carbon::today();
        $dayOfWeek = $today->format('l');
        $semester = Semester::where('is_active', true)->first();

        $totalVenues = Venue::where('status', 'available')->where('campus', $campus)->count();

        $busyVenueIds = collect();

        if ($semester) {
            $busyVenueIds = $busyVenueIds->merge(
                TimetableSlot::where('semester_id', $semester->id)
                    ->where('day_of_week', $dayOfWeek)
                    ->pluck('venue_id')
            );
        }

        $busyVenueIds = $busyVenueIds->merge(
            Booking::whereDate('booking_date', $today)
                ->whereIn('status', ['pending', 'approved'])
                ->pluck('venue_id')
        )->unique();

        $busyCount = Venue::where('status', 'available')->where('campus', $campus)->whereIn('id', $busyVenueIds)->count();

        return response()->json([
            'date' => $today->toDateString(),
            'day_of_week' => $dayOfWeek,
            'total_venues' => $totalVenues,
            'free_venues' => max(0, $totalVenues - $busyCount),
            'busy_venues' => $busyCount,
        ]);
    }

    /**
     * List of all usable venues (for general information, not availability).
     * Allows ?q= to search by venue name or number/code (e.g. "Ntare 108").
     */
    public function index(Request $request): JsonResponse
    {
        $venues = Venue::where('status', '!=', 'disabled')
            ->where('campus', $request->user()->campus)
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('building', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->get();

        $this->attachOccupiedUntil($venues);

        return response()->json($venues);
    }

    /**
     * For each venue, if it's occupied RIGHT NOW (an official lecture or a
     * booking covering the current moment, today), sets a dynamic
     * `occupied_until` (HH:MM) attribute so a CR browsing venues can see
     * "free again at 18:00" instead of just a static Available/Maintenance
     * badge. Back-to-back intervals (e.g. two chained lecture slots) are
     * merged so the shown time reflects when the venue is genuinely free.
     * Left null for a venue that's free right now.
     */
    private function attachOccupiedUntil($venues): void
    {
        if ($venues->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $dayOfWeek = $now->format('l');
        $nowTime = $now->format('H:i');
        $venueIds = $venues->pluck('id');
        $semester = Semester::where('is_active', true)->first();

        $timetable = $semester
            ? TimetableSlot::where('semester_id', $semester->id)
                ->where('day_of_week', $dayOfWeek)
                ->whereIn('venue_id', $venueIds)
                ->get(['venue_id', 'start_time', 'end_time'])
            : collect();

        $bookings = Booking::whereDate('booking_date', $now->toDateString())
            ->whereIn('status', ['pending', 'approved'])
            ->whereIn('venue_id', $venueIds)
            ->get(['venue_id', 'start_time', 'end_time']);

        $venues->each(function (Venue $venue) use ($timetable, $bookings, $nowTime) {
            // concat(), not merge() - see the note in available() for why.
            $intervals = $timetable->where('venue_id', $venue->id)
                ->concat($bookings->where('venue_id', $venue->id))
                ->map(fn ($b) => [
                    'start' => substr((string) $b->start_time, 0, 5),
                    'end' => (string) $b->end_time === '00:00' ? '23:59' : substr((string) $b->end_time, 0, 5),
                ])
                ->sortBy('start')
                ->values();

            $occupiedUntil = null;

            foreach ($intervals as $interval) {
                if ($interval['start'] <= $nowTime && $nowTime < $interval['end']) {
                    $occupiedUntil = $interval['end'];
                } elseif ($occupiedUntil !== null && $interval['start'] <= $occupiedUntil) {
                    $occupiedUntil = max($occupiedUntil, $interval['end']);
                }
            }

            $venue->occupied_until = $occupiedUntil;
        });
    }

    /**
     * CORE OF THE SYSTEM: show 'Available Venues' for the Semester + Date + Time
     * (start/end time) chosen by the CR. A venue counts as 'available' if:
     *   1) Its status is not 'maintenance' or 'disabled', AND
     *   2) It doesn't clash with any Timetable Slot (official lecture schedule - from the Mzumbe timetable), AND
     *   3) It doesn't clash with another Booking (pending/approved) from other CRs for that date/time.
     *
     * Query params: semester_id, date (YYYY-MM-DD), start_time (HH:MM), end_time (HH:MM)
     */
    public function available(Request $request): JsonResponse
    {
        $data = $request->validate([
            'semester_id' => ['required', 'exists:semesters,id'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        if (! Semester::where('id', $data['semester_id'])->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'This semester is not currently active. Choose an active semester.',
                'venues' => [],
            ], 200);
        }

        $dayOfWeek = Carbon::parse($data['date'])->format('l');

        $timetableBusyIds = TimetableSlot::where('semester_id', $data['semester_id'])
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->pluck('venue_id');

        $bookingBusyIds = Booking::where('semester_id', $data['semester_id'])
            ->whereDate('booking_date', $data['date'])
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->pluck('venue_id');

        $busyIds = $timetableBusyIds->merge($bookingBusyIds)->unique();

        $availableVenues = Venue::where('status', 'available')
            ->where('campus', $request->user()->campus)
            ->whereNotIn('id', $busyIds)
            ->orderBy('name')
            ->get()
            ->filter(fn (Venue $venue) => $venue->allowsUser($request->user()))
            ->values();

        if ($availableVenues->isEmpty()) {
            return response()->json([
                'message' => 'No Available Venues',
                'venues' => [],
            ], 200);
        }

        // For each venue, compute the full time range that is 'free' (not just
        // the requested time), so the CR sees e.g. "Free 09:00-11:00" instead
        // of only the single time slot they asked about.
        $allTimetable = TimetableSlot::where('semester_id', $data['semester_id'])
            ->where('day_of_week', $dayOfWeek)
            ->whereIn('venue_id', $availableVenues->pluck('id'))
            ->get(['venue_id', 'start_time', 'end_time']);

        $allBookings = Booking::whereDate('booking_date', $data['date'])
            ->whereIn('status', ['pending', 'approved'])
            ->whereIn('venue_id', $availableVenues->pluck('id'))
            ->get(['venue_id', 'start_time', 'end_time']);

        $availableVenues->each(function (Venue $venue) use ($allTimetable, $allBookings, $data) {
            // concat(), not merge() - Eloquent\Collection::merge() dedupes by
            // the model's primary key (getKey()), and these rows were
            // fetched without `id` (only the 3 columns needed here), so two
            // unrelated TimetableSlot/Booking rows could silently collide
            // and one would be dropped from the interval list.
            $busy = $allTimetable->where('venue_id', $venue->id)
                ->concat($allBookings->where('venue_id', $venue->id))
                ->map(fn ($b) => ['start' => (string) $b->start_time, 'end' => (string) $b->end_time])
                ->sortBy('start')
                ->values();

            $freeFrom = '06:00';
            $freeUntil = '22:00';

            foreach ($busy as $interval) {
                if ($interval['end'] <= $data['start_time'] && $interval['end'] > $freeFrom) {
                    $freeFrom = $interval['end'];
                }
                if ($interval['start'] >= $data['end_time'] && $interval['start'] < $freeUntil) {
                    $freeUntil = $interval['start'];
                }
            }

            $venue->free_from = $freeFrom;
            $venue->free_until = $freeUntil;
        });

        return response()->json([
            'message' => 'Available Venues',
            'venues' => $availableVenues,
        ]);
    }

    /**
     * 'Booked Venues' - the schedule of occupied venues (morning to evening)
     * for a given date, combining Timetable Slots (official lectures) and
     * Bookings (from CRs).
     */
    public function booked(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $dayOfWeek = Carbon::parse($data['date'])->format('l');
        $campus = $request->user()->campus;
        $activeSemester = Semester::where('is_active', true)->first();

        $timetable = $activeSemester
            ? TimetableSlot::with('venue')
                ->whereHas('venue', fn ($q) => $q->where('campus', $campus))
                ->where('semester_id', $activeSemester->id)
                ->where('day_of_week', $dayOfWeek)
                ->orderBy('start_time')
                ->get()
            : collect();

        $bookings = Booking::with(['venue', 'user'])
            ->whereHas('venue', fn ($q) => $q->where('campus', $campus))
            ->whereDate('booking_date', $data['date'])
            ->whereIn('status', ['pending', 'approved'])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'date' => $data['date'],
            'day_of_week' => $dayOfWeek,
            'timetable' => $timetable,
            'bookings' => $bookings,
        ]);
    }
}
