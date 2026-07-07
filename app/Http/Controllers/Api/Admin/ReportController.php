<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Tafsiri 'range' kuwa [tarehe_ya_kuanzia, tarehe_ya_mwisho] (null = hakuna kikomo).
     * Chaguo: today, yesterday, this_week, this_month, all.
     */
    private function rangeToDates(string $range): array
    {
        $today = Carbon::today();

        return match ($range) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            default => [null, null],
        };
    }

    /**
     * Muhtasari wa jumla: idadi ya bookings kwa status, venue zinazotumika zaidi, n.k.
     * Query param 'range' => today | yesterday | this_week | this_month | all (default).
     */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->rangeToDates($request->string('range', 'all'));

        $query = Booking::query()
            ->when($request->filled('semester_id'), fn ($q) => $q->where('semester_id', $request->integer('semester_id')))
            ->when($from && $to, fn ($q) => $q->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()]));

        $byStatus = (clone $query)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $mostBookedVenues = (clone $query)
            ->select('venue_id', DB::raw('count(*) as total'))
            ->whereIn('status', ['approved', 'pending'])
            ->groupBy('venue_id')
            ->orderByDesc('total')
            ->with('venue:id,name,building')
            ->limit(10)
            ->get();

        $byPurpose = (clone $query)
            ->select('purpose', DB::raw('count(*) as total'))
            ->groupBy('purpose')
            ->pluck('total', 'purpose');

        $crQuery = User::where('role', 'cr');

        return response()->json([
            'total_bookings' => (clone $query)->count(),
            'by_status' => $byStatus,
            'by_purpose' => $byPurpose,
            'most_booked_venues' => $mostBookedVenues,
            'total_venues' => Venue::count(),
            'total_crs' => (clone $crQuery)->count(),
            'male_crs' => (clone $crQuery)->where('sex', 'male')->count(),
            'female_crs' => (clone $crQuery)->where('sex', 'female')->count(),
        ]);
    }

    /**
     * Ripoti ya matumizi ya venue moja kwa kipindi fulani.
     */
    public function venueUsage(Request $request, Venue $venue): JsonResponse
    {
        $bookings = $venue->bookings()
            ->with('user:id,name,program')
            ->when($request->filled('semester_id'), fn ($q) => $q->where('semester_id', $request->integer('semester_id')))
            ->orderByDesc('booking_date')
            ->get();

        return response()->json([
            'venue' => $venue,
            'total_bookings' => $bookings->count(),
            'bookings' => $bookings,
        ]);
    }

    /**
     * Pakua ripoti ya bookings zote (CSV), ikichujwa na status/date/venue kama
     * inavyofanya BookingAdminController::index.
     */
    public function exportBookings(Request $request): StreamedResponse
    {
        $bookings = Booking::with(['user:id,name,email,program', 'venue:id,name,campus'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->string('date')))
            ->when($request->filled('venue_id'), fn ($q) => $q->where('venue_id', $request->integer('venue_id')))
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->get();

        $filename = 'bookings_report.csv';

        $callback = function () use ($bookings) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['#', 'Venue', 'Campus', 'Date', 'Start', 'End', 'Booked By', 'Email', 'Program', 'Purpose', 'Status']);

            foreach ($bookings as $i => $b) {
                fputcsv($handle, [
                    $i + 1,
                    $b->venue?->name,
                    $b->venue?->campus,
                    $b->booking_date?->format('Y-m-d'),
                    $b->start_time,
                    $b->end_time,
                    $b->user?->name,
                    $b->user?->email,
                    $b->user?->program,
                    $b->purpose,
                    $b->status,
                ]);
            }

            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
