<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Booking;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * The rules shared by creating a booking (BookingController::store) and
 * editing one (BookingController::update, BookingAdminController::update):
 * the "00:00 means midnight" normalization, duration caps, the Study Unit
 * window, venue status/purpose/user restrictions, and clashes against the
 * timetable or another booking. Kept as one class so a CR's and an Admin's
 * edit endpoints can't quietly drift out of sync with each other or with
 * booking creation.
 */
class BookingRuleChecker
{
    public const STUDY_UNIT_MAX_MINUTES = 240;

    /**
     * @param  array  $data  Validated request data - mutated in place (the
     *                       "00:00" -> "23:59" end_time normalization).
     * @param  User  $actingUser  Whoever is making the request - used for
     *                            conflict-log attribution.
     * @param  User|null  $restrictionUser  Whose campus/level/department the
     *                                      venue's access restrictions are
     *                                      checked against. Defaults to
     *                                      $actingUser (the CR booking for
     *                                      themself). When an Admin edits a
     *                                      CR's booking on their behalf, pass
     *                                      the CR here instead - the venue's
     *                                      restrictions describe who the
     *                                      booking is FOR, not who's typing.
     * @return Venue|JsonResponse The resolved Venue on success, or the error
     *                            response to return as-is on failure.
     */
    public function check(array &$data, User $actingUser, ?int $excludingBookingId = null, ?User $restrictionUser = null): Venue|JsonResponse
    {
        $restrictionUser ??= $actingUser;
        $dayOfWeek = Carbon::parse($data['booking_date'])->format('l');

        if ($data['end_time'] === '00:00') {
            $data['end_time'] = '23:59';
        }

        if ($data['end_time'] <= $data['start_time']) {
            return response()->json(['message' => 'End time must be after start time.'], 422);
        }

        if ($data['purpose'] === 'test') {
            $durationMinutes = Carbon::parse($data['start_time'])->diffInMinutes(Carbon::parse($data['end_time']));

            if ($durationMinutes > 60) {
                return response()->json([
                    'message' => 'A Test booking cannot be longer than 1 hour (e.g. 17:00-18:00). Please shorten the time.',
                ], 422);
            }
        }

        if ($data['purpose'] === 'study_unit') {
            $configuredHours = AppSetting::current()->study_unit_hours[$dayOfWeek] ?? null;
            $windowStart = $configuredHours['start'] ?? '19:00';
            $windowEndRaw = $configuredHours['end'] ?? '00:00';
            $windowEnd = $windowEndRaw === '00:00' ? '23:59' : $windowEndRaw;
            $windowEndLabel = $windowEndRaw === '00:00' ? 'midnight' : $windowEndRaw;

            if ($data['start_time'] < $windowStart || $data['start_time'] > $windowEnd) {
                return response()->json([
                    'message' => "Study Unit bookings on {$dayOfWeek} are only allowed from {$windowStart} until {$windowEndLabel}.",
                ], 422);
            }

            if ($data['end_time'] > $windowEnd) {
                return response()->json([
                    'message' => "Study Unit bookings on {$dayOfWeek} must end by {$windowEndLabel} - choose an earlier end time.",
                ], 422);
            }
        }

        $venue = Venue::findOrFail($data['venue_id']);

        if ($venue->status !== 'available') {
            return response()->json([
                'message' => 'This venue is currently unavailable (maintenance/disabled).',
            ], 422);
        }

        if (! $venue->allowsPurpose($data['purpose'])) {
            return response()->json([
                'message' => "Venue {$venue->name} is not allowed for ".str_replace('_', ' ', $data['purpose']).'.',
            ], 422);
        }

        if (! $venue->allowsUser($restrictionUser)) {
            return response()->json([
                'message' => "Venue {$venue->name} has special restrictions (campus/level/department) that you don't meet.",
            ], 403);
        }

        $clashesWithTimetable = TimetableSlot::overlapping(
            $data['venue_id'],
            $dayOfWeek,
            $data['start_time'],
            $data['end_time']
        )->where('semester_id', $data['semester_id'])->exists();

        if ($clashesWithTimetable) {
            return response()->json([
                'message' => 'This time slot already has an official lecture (timetable) at this venue.',
            ], 409);
        }

        $conflictingBooking = Booking::overlapping(
            $data['venue_id'],
            $data['booking_date'],
            $data['start_time'],
            $data['end_time'],
            $excludingBookingId
        )->with('user:id,name')->first();

        if ($conflictingBooking) {
            $conflictPurpose = str_replace('_', ' ', $conflictingBooking->purpose);
            $conflictMessage = "{$venue->name} is currently booked by {$conflictingBooking->user->name} for a {$conflictPurpose} "
                ."from {$conflictingBooking->start_time} until {$conflictingBooking->end_time} on "
                .Carbon::parse($conflictingBooking->booking_date)->format('d/m/Y')
                .". Please wait until then, or choose another time or venue.";

            ActivityLog::record(
                $actingUser->id,
                'booking_conflict',
                "{$actingUser->name} attempted to book {$venue->name} but it conflicted with a booking by {$conflictingBooking->user->name}.",
                $conflictingBooking->id
            );

            return response()->json(['message' => $conflictMessage], 409);
        }

        return $venue;
    }
}
