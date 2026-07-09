<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmedMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\User;
use App\Services\BookingRuleChecker;
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

    private const EDITABLE_STATUSES = ['pending', 'approved'];

    /** How long after creating a booking a CR may still self-edit it. */
    private const EDIT_WINDOW_MINUTES = 10;

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
        $data = $request->validate($this->validationRules());

        $result = (new BookingRuleChecker())->check($data, $request->user());

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $venue = $result;
        $needsReview = $this->needsReview($request->user(), $data, null);

        if ($needsReview && empty($data['reason'])) {
            throw ValidationException::withMessages([
                'reason' => $this->reasonPrompt($request->user(), $data),
            ]);
        }

        // No conflict was found (already checked above against the timetable
        // and other bookings), so - unless it needs review above - the
        // booking is approved immediately without waiting for an Admin,
        // since the system itself has already verified it.
        $booking = Booking::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => $needsReview ? 'pending' : 'approved',
            ...($needsReview ? [] : ['approved_at' => now(), 'signed_at' => now()]),
        ]);

        $booking->load(['venue', 'user']);

        if ($needsReview) {
            ActivityLog::record(
                $request->user()->id,
                'booking_pending_review',
                "{$booking->user->name} requested a booking of {$booking->venue->name} on "
                    .Carbon::parse($booking->booking_date)->format('d/m/Y')
                    ." that needs Admin review. Reason: {$data['reason']}",
                $booking->id
            );

            $this->notifyAdminsOfReview($request->user(), $booking, $data['reason']);
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
     * Let a CR fix a booking they got wrong (date/time/venue/purpose) rather
     * than cancel and start over. Only while it's still Pending or Approved
     * (not yet Rejected/Cancelled), and only within EDIT_WINDOW_MINUTES of
     * submitting it - a short grace period to catch a mistake, not an
     * open-ended edit right (an Admin's edit endpoint has no such limit).
     * Runs through the exact same rules as creating a new booking, and can
     * just as easily land back on Pending if the new time now needs Admin
     * review.
     */
    public function update(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403, 'You do not have permission.');
        abort_unless(
            in_array($booking->status, self::EDITABLE_STATUSES, true),
            422,
            'This booking is already closed and can no longer be edited - make a new booking instead.'
        );
        abort_if(
            $booking->created_at->diffInMinutes(now()) > self::EDIT_WINDOW_MINUTES,
            422,
            'You can only edit a booking within '.self::EDIT_WINDOW_MINUTES.' minutes of creating it - that window has passed.'
        );

        $data = $request->validate($this->validationRules());

        $result = (new BookingRuleChecker())->check($data, $request->user(), $booking->id);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $needsReview = $this->needsReview($request->user(), $data, $booking->id);

        if ($needsReview && empty($data['reason'])) {
            throw ValidationException::withMessages([
                'reason' => $this->reasonPrompt($request->user(), $data),
            ]);
        }

        $wasApproved = $booking->status === 'approved';

        $booking->update([
            ...$data,
            'status' => $needsReview ? 'pending' : 'approved',
            'approved_by' => null,
            'rejection_reason' => null,
            ...($needsReview ? ['approved_at' => null, 'signed_at' => null] : ['approved_at' => now(), 'signed_at' => now()]),
        ]);

        $booking->load(['venue', 'user']);

        ActivityLog::record(
            $request->user()->id,
            'booking_edited',
            "{$booking->user->name} edited their booking of {$booking->venue->name} to "
                .Carbon::parse($booking->booking_date)->format('d/m/Y')
                ." {$booking->start_time}-{$booking->end_time}"
                .($needsReview ? ' (now needs Admin review).' : '.'),
            $booking->id
        );

        if ($needsReview) {
            $this->notifyAdminsOfReview($request->user(), $booking, $data['reason'] ?? '');
        } elseif ($wasApproved) {
            // Was already approved and still is after the edit (e.g. just
            // moved to a different still-conflict-free time) - re-confirm.
            Mail::to($booking->user->email)->queue(new BookingConfirmedMail($booking));
        }

        return response()->json($booking);
    }

    /**
     * Whether this booking needs a written reason and a campus Admin's
     * review before it can be approved - either because it's beyond the
     * CR's free daily allowance, or (for Study Unit specifically) beyond
     * the single-booking duration allowance.
     */
    private function needsReview(User $user, array $data, ?int $excludingBookingId): bool
    {
        $bookingsTodayCount = Booking::where('user_id', $user->id)
            ->whereDate('booking_date', $data['booking_date'])
            ->whereIn('status', ['pending', 'approved'])
            ->when($excludingBookingId, fn ($q) => $q->where('id', '!=', $excludingBookingId))
            ->count();

        $exceedsDailyLimit = $bookingsTodayCount >= self::DAILY_AUTO_APPROVE_LIMIT;

        $exceedsStudyUnitDuration = $data['purpose'] === 'study_unit'
            && Carbon::parse($data['start_time'])->diffInMinutes(Carbon::parse($data['end_time'])) > BookingRuleChecker::STUDY_UNIT_MAX_MINUTES;

        return $exceedsDailyLimit || $exceedsStudyUnitDuration;
    }

    private function reasonPrompt(User $user, array $data): string
    {
        $exceedsStudyUnitDuration = $data['purpose'] === 'study_unit'
            && Carbon::parse($data['start_time'])->diffInMinutes(Carbon::parse($data['end_time'])) > BookingRuleChecker::STUDY_UNIT_MAX_MINUTES;

        if ($exceedsStudyUnitDuration) {
            return 'Study Unit bookings longer than '.(BookingRuleChecker::STUDY_UNIT_MAX_MINUTES / 60)
                .' hours need a reason, so your campus Admin can review it.';
        }

        return 'You have already booked '.self::DAILY_AUTO_APPROVE_LIMIT
            .' venue(s) today. Please explain why you need another one, so your campus Admin can review it.';
    }

    private function notifyAdminsOfReview(User $requester, Booking $booking, string $reason): void
    {
        $campus = $requester->campus;
        $adminIds = User::where('role', 'admin')
            ->where(fn ($q) => $q->where('is_super_admin', true)->orWhere('campus', $campus))
            ->pluck('id');

        $now = now();
        $rows = $adminIds->map(fn ($adminId) => [
            'user_id' => $adminId,
            'type' => 'booking_pending',
            'title' => "{$booking->user->name} requested {$booking->venue->name}",
            'body' => $reason,
            'booking_id' => $booking->id,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            Notification::insert($rows);
        }
    }

    private function validationRules(): array
    {
        return [
            'venue_id' => ['required', 'exists:venues,id'],
            'semester_id' => ['required', 'exists:semesters,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'purpose' => ['required', Rule::in(['study_unit', 'test', 'makeup_class', 'meeting', 'other'])],
            'title' => ['nullable', 'string', 'max:255'],
            'signature' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
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
