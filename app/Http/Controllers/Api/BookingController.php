<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmedMail;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * A CR gets up to this many bookings auto-approved per calendar day;
     * beyond that they must give a reason and a campus Admin reviews it.
     */
    private const DAILY_AUTO_APPROVE_LIMIT = 2;

    /**
     * Bookings belonging to the logged-in CR.
     */
    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 20);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $bookings = Booking::with(['venue', 'semester', 'approver'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate($perPage);

        return response()->json($bookings);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->user_id === $request->user()->id || $request->user()->isAdmin(),
            403,
            'You do not have permission to view this booking.'
        );

        return response()->json($booking->load(['venue', 'semester', 'approver']));
    }

    /**
     * Create a new booking. Before saving, we make sure the selected time
     * doesn't clash with a Timetable Slot (official lecture) or another
     * existing Booking (pending/approved) for that venue - this is what
     * prevents "double booking".
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'semester_id' => ['required', 'exists:semesters,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'purpose' => ['required', Rule::in(['study_unit', 'test', 'makeup_class', 'meeting', 'other'])],
            'title' => ['nullable', 'string', 'max:255'],
            'signature' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $dayOfWeek = Carbon::parse($data['booking_date'])->format('l');

        // "00:00" in the user's request means "until midnight" (end of day).
        // We store it as "23:59" instead because all overlap comparisons in
        // the system are for a single day's time range (they have no concept
        // of crossing into the next day) - "00:00" would break those comparisons.
        if ($data['end_time'] === '00:00') {
            $data['end_time'] = '23:59';
        }

        if ($data['end_time'] <= $data['start_time']) {
            return response()->json(['message' => 'End time must be after start time.'], 422);
        }

        // A "Test" is meant to be short - cap it at 1 hour so it doesn't tie
        // up a venue as long as a full Study Unit session would.
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

            // The start time alone being in-window isn't enough - the booking
            // must also finish by the end of the window, or it would quietly
            // run past the hours the Admin configured.
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

        if (! $venue->allowsUser($request->user())) {
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
            $data['end_time']
        )->with('user:id,name')->first();

        if ($conflictingBooking) {
            $conflictPurpose = str_replace('_', ' ', $conflictingBooking->purpose);
            $conflictMessage = "{$venue->name} is currently booked by {$conflictingBooking->user->name} for a {$conflictPurpose} "
                ."from {$conflictingBooking->start_time} until {$conflictingBooking->end_time} on "
                .Carbon::parse($conflictingBooking->booking_date)->format('d/m/Y')
                .". Please wait until then, or choose another time or venue.";

            ActivityLog::record(
                $request->user()->id,
                'booking_conflict',
                "{$request->user()->name} attempted to book {$venue->name} but it conflicted with a booking by {$conflictingBooking->user->name}.",
                $conflictingBooking->id
            );

            return response()->json(['message' => $conflictMessage], 409);
        }

        // A CR can have up to DAILY_AUTO_APPROVE_LIMIT bookings auto-approved
        // per calendar day; beyond that they must explain why, and the
        // booking goes to their campus Admin for manual review instead of
        // being auto-approved (same pending/approve flow as any other
        // booking an Admin reviews).
        $bookingsTodayCount = Booking::where('user_id', $request->user()->id)
            ->whereDate('booking_date', $data['booking_date'])
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        $exceedsDailyLimit = $bookingsTodayCount >= self::DAILY_AUTO_APPROVE_LIMIT;

        if ($exceedsDailyLimit && empty($data['reason'])) {
            throw ValidationException::withMessages([
                'reason' => 'You have already booked '.self::DAILY_AUTO_APPROVE_LIMIT
                    .' venue(s) today. Please explain why you need another one, so your campus Admin can review it.',
            ]);
        }

        // No conflict was found (already checked above against the timetable
        // and other bookings), so - unless the daily limit above was exceeded
        // - the booking is approved immediately without waiting for an
        // Admin, since the system itself has already verified it.
        $booking = Booking::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => $exceedsDailyLimit ? 'pending' : 'approved',
            ...($exceedsDailyLimit ? [] : ['approved_at' => now(), 'signed_at' => now()]),
        ]);

        $booking->load(['venue', 'user']);

        if ($exceedsDailyLimit) {
            ActivityLog::record(
                $request->user()->id,
                'booking_pending_review',
                "{$booking->user->name} requested an extra booking of {$booking->venue->name} on "
                    .Carbon::parse($booking->booking_date)->format('d/m/Y')
                    ." beyond the daily limit, pending Admin review. Reason: {$data['reason']}",
                $booking->id
            );

            $campus = $request->user()->campus;
            $adminIds = User::where('role', 'admin')
                ->where(fn ($q) => $q->where('is_super_admin', true)->orWhere('campus', $campus))
                ->pluck('id');

            $now = now();
            $rows = $adminIds->map(fn ($adminId) => [
                'user_id' => $adminId,
                'type' => 'booking_pending',
                'title' => "{$booking->user->name} requested {$booking->venue->name}",
                'body' => $data['reason'],
                'booking_id' => $booking->id,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($rows !== []) {
                Notification::insert($rows);
            }
        } else {
            ActivityLog::record(
                $request->user()->id,
                'booking_created',
                "{$booking->user->name} booked {$booking->venue->name} on ".Carbon::parse($booking->booking_date)->format('d/m/Y')
                    ." from {$booking->start_time} to {$booking->end_time} (auto-approved).",
                $booking->id
            );

            Mail::to($booking->user->email)->queue(new BookingConfirmedMail($booking));
        }

        return response()->json($booking, 201);
    }

    /**
     * "Digital Signature/Confirmation" - the CR confirms their booking after
     * it has been approved by an Admin (status = approved).
     */
    public function sign(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403, 'You do not have permission.');

        if ($booking->status !== 'approved') {
            return response()->json([
                'message' => 'The booking must be approved before signing.',
            ], 422);
        }

        $data = $request->validate([
            'signature' => ['required', 'string'],
        ]);

        $booking->update([
            'signature' => $data['signature'],
            'signed_at' => now(),
        ]);

        return response()->json($booking);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403, 'You do not have permission.');

        abort_if(
            in_array($booking->status, ['cancelled', 'rejected']),
            422,
            'This booking is already closed.'
        );

        $booking->update(['status' => 'cancelled']);

        ActivityLog::record(
            $request->user()->id,
            'booking_cancelled',
            "{$request->user()->name} cancelled their booking of {$booking->venue->name} on ".Carbon::parse($booking->booking_date)->format('d/m/Y').'.',
            $booking->id
        );

        return response()->json($booking);
    }
}
