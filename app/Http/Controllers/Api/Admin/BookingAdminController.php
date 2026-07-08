<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 30);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);

        $bookings = Booking::with(['user', 'venue', 'semester'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->string('date')))
            ->when($request->filled('venue_id'), fn ($q) => $q->where('venue_id', $request->integer('venue_id')))
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate($perPage);

        return response()->json($bookings);
    }

    public function approve(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->status === 'pending', 422, 'Booking hii tayari imeshughulikiwa.');

        $booking->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        ActivityLog::record(
            $request->user()->id,
            'booking_approved',
            "Admin ameidhinisha booking ya {$booking->venue->name} kwa {$booking->user->name}.",
            $booking->id
        );

        return response()->json($booking);
    }

    public function reject(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->status === 'pending', 422, 'Booking hii tayari imeshughulikiwa.');

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
            "Admin amekataa booking ya {$booking->venue->name} kwa {$booking->user->name}: {$data['rejection_reason']}",
            $booking->id
        );

        return response()->json($booking);
    }

    /**
     * Futa kabisa rekodi ya booking MOJA - inaruhusiwa kwa status yoyote
     * (pending/approved/rejected/cancelled), tofauti na cancel() ya CR ambayo
     * ni "soft" (inabadilisha status tu, siyo kufuta).
     */
    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $venueName = $booking->venue?->name ?? "Venue #{$booking->venue_id}";
        $userName = $booking->user?->name ?? "User #{$booking->user_id}";
        $status = $booking->status;

        $booking->delete();

        ActivityLog::record(
            $request->user()->id,
            'booking_deleted',
            "{$request->user()->name} permanently deleted a {$status} booking of {$venueName} by {$userName}."
        );

        return response()->json(['message' => 'Booking imefutwa.']);
    }

    /**
     * Futa rekodi ZOTE za bookings zinazolingana na filters za sasa
     * (status/date/venue), kama inavyofanya index().
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $bookings = Booking::with(['user', 'venue'])
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

        return response()->json(['message' => "Bookings {$count} zimefutwa.", 'deleted' => $count]);
    }
}
