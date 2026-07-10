<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmedMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class BookingAdminController extends Controller
{
    /**
     * A regular Admin only sees/manages bookings for their own campus
     * (via venue.campus) - a Super Admin sees all of them.
     */
    private function assertBookingCampusAllowed(Request $request, Booking $booking): void
    {
        $scope = $request->user()->campusScope();
        abort_if($scope && $booking->venue?->campus !== $scope, 403, 'You can only manage bookings for your own campus.');
    }

    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 30);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);
        $campusScope = $request->user()->campusScope();

        $bookings = Booking::with(['user', 'venue', 'semester', 'approver'])
            ->when($campusScope, fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $campusScope)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->string('date')))
            ->when($request->filled('venue_id'), fn ($q) => $q->where('venue_id', $request->integer('venue_id')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->where(function ($w) use ($term) {
                    $w->where('purpose', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('reason', 'like', "%{$term}%")
                        ->orWhere('start_time', 'like', "%{$term}%")
                        ->orWhere('end_time', 'like', "%{$term}%")
                        ->orWhereHas('venue', fn ($v) => $v->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate($perPage);

        return response()->json($bookings);
    }

    public function approve(Request $request, Booking $booking): JsonResponse
    {
        $this->assertBookingCampusAllowed($request, $booking);
        abort_unless($booking->status === 'pending', 422, 'This booking has already been handled.');

        $booking->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        ActivityLog::record(
            $request->user()->id,
            'booking_approved',
            "Admin approved the booking of {$booking->venue->name} for {$booking->user->name}.",
            $booking->id
        );

        Notification::send(
            $booking->user_id,
            'booking_approved',
            "Your booking for {$booking->venue->name} was approved",
            null,
            $booking->id
        );

        // Sent synchronously (not ->queue()) so it doesn't depend on a queue
        // worker running - an Admin approving a booking is a one-off action,
        // not high-volume, so the extra request time is an acceptable trade.
        Mail::to($booking->user->email)->send(new BookingConfirmedMail($booking, approvedByAdmin: true));

        return response()->json($booking);
    }

    public function reject(Request $request, Booking $booking): JsonResponse
    {
        $this->assertBookingCampusAllowed($request, $booking);
        abort_unless($booking->status === 'pending', 422, 'This booking has already been handled.');

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $booking->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);

        ActivityLog::record(
            $request->user()->id,
            'booking_rejected',
            "Admin rejected the booking of {$booking->venue->name} for {$booking->user->name}: {$data['rejection_reason']}",
            $booking->id
        );

        Notification::send(
            $booking->user_id,
            'booking_rejected',
            "Your booking for {$booking->venue->name} was rejected",
            $data['rejection_reason'],
            $booking->id
        );

        return response()->json($booking);
    }

    /**
     * Permanently delete a SINGLE booking record - allowed for any status
     * (pending/approved/rejected/cancelled), unlike the CR's cancel() which
     * is "soft" (it just changes the status, doesn't delete).
     */
    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $this->assertBookingCampusAllowed($request, $booking);

        $venueName = $booking->venue?->name ?? "Venue #{$booking->venue_id}";
        $userName = $booking->user?->name ?? "User #{$booking->user_id}";
        $status = $booking->status;

        $booking->delete();

        ActivityLog::record(
            $request->user()->id,
            'booking_deleted',
            "{$request->user()->name} permanently deleted a {$status} booking of {$venueName} by {$userName}."
        );

        return response()->json(['message' => 'Booking deleted.']);
    }

    /**
     * Permanently delete ALL booking records matching the current filters
     * (status/date/venue), same as index() - restricted to the Admin's
     * campus (unless they are a Super Admin).
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $campusScope = $request->user()->campusScope();

        $bookings = Booking::with(['user', 'venue'])
            ->when($campusScope, fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $campusScope)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->string('date')))
            ->when($request->filled('venue_id'), fn ($q) => $q->where('venue_id', $request->integer('venue_id')))
            ->get();

        $count = $bookings->count();

        foreach ($bookings as $booking) {
            $booking->delete();
        }

        $scope = $request->filled('status') ? "with status \"{$request->string('status')}\"" : 'of all statuses';

        ActivityLog::record(
            $request->user()->id,
            'booking_deleted',
            "{$request->user()->name} permanently deleted {$count} booking(s) {$scope}."
        );

        return response()->json(['message' => "{$count} booking(s) deleted.", 'deleted' => $count]);
    }
}
