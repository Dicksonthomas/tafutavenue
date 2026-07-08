<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingAdminController extends Controller
{
    /**
     * Admin wa kawaida anaona/anasimamia bookings za campus yake pekee
     * (kupitia venue.campus) - Super Admin anaona zote.
     */
    private function assertBookingCampusAllowed(Request $request, Booking $booking): void
    {
        $scope = $request->user()->campusScope();
        abort_if($scope && $booking->venue?->campus !== $scope, 403, 'Unaweza kusimamia bookings za campus yako pekee.');
    }

    public function index(Request $request): JsonResponse
    {
        $perPageInput = $request->input('per_page', 30);
        $perPage = ($perPageInput === 'all' || ! is_numeric($perPageInput)) ? 100000 : max(1, (int) $perPageInput);
        $campusScope = $request->user()->campusScope();

        $bookings = Booking::with(['user', 'venue', 'semester'])
            ->when($campusScope, fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $campusScope)))
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
        $this->assertBookingCampusAllowed($request, $booking);
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
        $this->assertBookingCampusAllowed($request, $booking);
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

        return response()->json(['message' => 'Booking imefutwa.']);
    }

    /**
     * Futa rekodi ZOTE za bookings zinazolingana na filters za sasa
     * (status/date/venue), kama inavyofanya index() - zimefungiwa campus
     * ya Admin (kama siyo Super Admin).
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

        return response()->json(['message' => "Bookings {$count} zimefutwa.", 'deleted' => $count]);
    }
}
