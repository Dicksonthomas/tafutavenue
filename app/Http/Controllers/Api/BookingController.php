<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmedMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\TimetableSlot;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    /**
     * Bookings za CR aliye-login.
     */
    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::with(['venue', 'semester'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate(20);

        return response()->json($bookings);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        abort_unless(
            $booking->user_id === $request->user()->id || $request->user()->isAdmin(),
            403,
            'Huna ruhusa ya kuona booking hii.'
        );

        return response()->json($booking->load(['venue', 'semester', 'approver']));
    }

    /**
     * Tengeneza booking mpya. Kabla ya kuhifadhi, tunahakikisha muda uliochaguliwa
     * haujagongana na Timetable Slot (mihadhara rasmi) wala Booking nyingine iliyopo
     * (pending/approved) kwa venue hiyo - hii ndiyo inayozuia 'double booking'.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'semester_id' => ['required', 'exists:semesters,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'purpose' => ['required', Rule::in(['study_unit', 'test', 'makeup_class', 'meeting', 'other'])],
            'title' => ['nullable', 'string', 'max:255'],
            'signature' => ['required', 'string'],
        ]);

        $venue = Venue::findOrFail($data['venue_id']);

        if ($venue->status !== 'available') {
            return response()->json([
                'message' => 'Venue hii haipatikani kwa sasa (maintenance/disabled).',
            ], 422);
        }

        if (! $venue->allowsPurpose($data['purpose'])) {
            return response()->json([
                'message' => "Venue {$venue->name} haziruhusiwi kwa ajili ya ".str_replace('_', ' ', $data['purpose']).'.',
            ], 422);
        }

        if (! $venue->allowsUser($request->user())) {
            return response()->json([
                'message' => "Venue {$venue->name} ina masharti maalum (campus/level/department) ambayo huna ruhusa nayo.",
            ], 403);
        }

        $dayOfWeek = Carbon::parse($data['booking_date'])->format('l');

        $clashesWithTimetable = TimetableSlot::overlapping(
            $data['venue_id'],
            $dayOfWeek,
            $data['start_time'],
            $data['end_time']
        )->where('semester_id', $data['semester_id'])->exists();

        if ($clashesWithTimetable) {
            return response()->json([
                'message' => 'Muda huu tayari una mhadhara rasmi (timetable) kwenye venue hii.',
            ], 409);
        }

        $conflictingBooking = Booking::overlapping(
            $data['venue_id'],
            $data['booking_date'],
            $data['start_time'],
            $data['end_time']
        )->with('user:id,name')->first();

        if ($conflictingBooking) {
            $conflictMessage = "Venue {$venue->name} tayari imeshabookiwa na {$conflictingBooking->user->name} "
                ."kuanzia {$conflictingBooking->start_time} hadi {$conflictingBooking->end_time} tarehe "
                .Carbon::parse($conflictingBooking->booking_date)->format('d/m/Y').'.';

            ActivityLog::record(
                $request->user()->id,
                'booking_conflict',
                "{$request->user()->name} alijaribu ku-book {$venue->name} lakini iligongana na booking ya {$conflictingBooking->user->name}.",
                $conflictingBooking->id
            );

            return response()->json(['message' => $conflictMessage], 409);
        }

        // Hakuna mgongano wowote uliopatikana (tayari umekaguliwa hapo juu dhidi ya
        // timetable na bookings nyingine), kwa hiyo booking inaidhinishwa (approved)
        // moja kwa moja bila kusubiri Admin - mfumo wenyewe ndio umeshathibitisha.
        $booking = Booking::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => 'approved',
            'approved_at' => now(),
            'signed_at' => now(),
        ]);

        $booking->load(['venue', 'user']);

        ActivityLog::record(
            $request->user()->id,
            'booking_created',
            "{$booking->user->name} ame-book {$booking->venue->name} tarehe ".Carbon::parse($booking->booking_date)->format('d/m/Y')
                ." kuanzia {$booking->start_time} hadi {$booking->end_time} (auto-approved).",
            $booking->id
        );

        Mail::to($booking->user->email)->queue(new BookingConfirmedMail($booking));

        return response()->json($booking, 201);
    }

    /**
     * 'Digital Signature/Confirmation' - CR anathibitisha booking yake baada ya
     * kuidhinishwa na Admin (status = approved).
     */
    public function sign(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->user_id === $request->user()->id, 403, 'Huna ruhusa.');

        if ($booking->status !== 'approved') {
            return response()->json([
                'message' => 'Booking lazima iwe imeidhinishwa (approved) kabla ya kutia saini.',
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
        abort_unless($booking->user_id === $request->user()->id, 403, 'Huna ruhusa.');

        abort_if(
            in_array($booking->status, ['cancelled', 'rejected']),
            422,
            'Booking hii tayari imefungwa.'
        );

        $booking->update(['status' => 'cancelled']);

        return response()->json($booking);
    }
}
