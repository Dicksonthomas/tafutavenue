<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Translate 'range' into [start_date, end_date] (null = no limit).
     * Options: today, yesterday, this_week, this_month, all.
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
     * General summary: number of bookings by status, most-used venues, etc.
     * Query param 'range' => today | yesterday | this_week | this_month | all (default).
     */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->rangeToDates($request->string('range', 'all'));
        $campusScope = $request->user()->campusScope();

        $query = Booking::query()
            ->when($campusScope, fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $campusScope)))
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

        $crQuery = User::where('role', 'cr')
            ->when($campusScope, fn ($q) => $q->where('campus', $campusScope));

        $venueQuery = Venue::query()->when($campusScope, fn ($q) => $q->where('campus', $campusScope));

        $venuesByCampus = (clone $venueQuery)
            ->select('campus', DB::raw('count(*) as total'))
            ->groupBy('campus')
            ->pluck('total', 'campus');

        $crsByCampus = (clone $crQuery)
            ->select('campus', DB::raw('count(*) as total'))
            ->groupBy('campus')
            ->pluck('total', 'campus');

        return response()->json([
            'total_bookings' => (clone $query)->count(),
            'by_status' => $byStatus,
            'by_purpose' => $byPurpose,
            'most_booked_venues' => $mostBookedVenues,
            'total_venues' => (clone $venueQuery)->count(),
            'venues_by_campus' => $venuesByCampus,
            'total_crs' => (clone $crQuery)->count(),
            'crs_by_campus' => $crsByCampus,
            'male_crs' => (clone $crQuery)->where('sex', 'male')->count(),
            'female_crs' => (clone $crQuery)->where('sex', 'female')->count(),
        ]);
    }

    /**
     * Usage report for a single venue over a given period.
     */
    public function venueUsage(Request $request, Venue $venue): JsonResponse
    {
        $campusScope = $request->user()->campusScope();
        abort_if($campusScope && $venue->campus !== $campusScope, 403, 'You can only view reports for your own campus.');

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
     * Filtered bookings query (status/date/venue), used by both exports.
     */
    private function filteredBookingsQuery(Request $request): Builder
    {
        $campusScope = $request->user()->campusScope();

        return Booking::with(['user:id,name,email,program', 'venue:id,name,campus'])
            ->when($campusScope, fn ($q) => $q->whereHas('venue', fn ($v) => $v->where('campus', $campusScope)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->string('date')))
            ->when($request->filled('venue_id'), fn ($q) => $q->where('venue_id', $request->integer('venue_id')))
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time');
    }

    /**
     * Download the full bookings report (PDF), filtered by status/date/venue
     * the same way BookingAdminController::index does.
     */
    public function exportBookingsPdf(Request $request): Response|JsonResponse
    {
        // Generating a PDF for a large table can use more time/memory than
        // some hosting defaults allow (e.g. Railway) - raise the limit for
        // this request only so it doesn't get cut off midway.
        @ini_set('memory_limit', '512M');
        @set_time_limit(60);

        try {
            $bookings = $this->filteredBookingsQuery($request)->get();

            $pdf = Pdf::loadView('reports.bookings-pdf', [
                'bookings' => $bookings,
                'status' => $request->string('status')->toString(),
                'generatedAt' => now(),
            ])->setPaper('a4', 'landscape');

            return $pdf->download('bookings_report.pdf');
        } catch (\Throwable $e) {
            Log::error('Bookings PDF export failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'Failed to generate PDF. Try again or contact the system administrator.',
            ], 500);
        }
    }

    /**
     * Download the full bookings report (CSV), filtered by status/date/venue
     * the same way BookingAdminController::index does.
     */
    public function exportBookings(Request $request): StreamedResponse
    {
        $bookings = $this->filteredBookingsQuery($request)->get();

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
