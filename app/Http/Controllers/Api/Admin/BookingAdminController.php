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
        $bookings = Booking::with(['user', 'venue', 'semester'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->string('date')))
            ->when($request->filled('venue_id'), fn ($q) => $q->where('venue_id', $request->integer('venue_id')))
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate(30);

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
}
