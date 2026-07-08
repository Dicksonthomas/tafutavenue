<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 14px; }
        .header { background: #002f3a; color: #ffffff; padding: 14px 18px; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header p { margin: 4px 0 0; font-size: 13px; color: #cfdadd; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; padding: 8px; font-size: 12px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 8px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        tr:nth-child(even) { background: #fafafa; }
        .status { display: inline-block; padding: 3px 8px; border-radius: 10px; font-size: 12px; font-weight: bold; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f1f5f9; color: #475569; }
        .footer { margin-top: 16px; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Bookings Report</h1>
        <p>
            Generated {{ $generatedAt->format('d/m/Y H:i') }}
            @if($status) &middot; Status: {{ ucfirst($status) }} @endif
            @if($date) &middot; Date: {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} @endif
            &middot; Total: {{ $bookings->count() }}
        </p>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Venue</th>
                <th>Campus</th>
                <th>Date</th>
                <th>Start</th>
                <th>End</th>
                <th>Booked By</th>
                <th>Email</th>
                <th>Program</th>
                <th>Purpose</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bookings as $i => $b)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $b->venue?->name }}</td>
                    <td>{{ $b->venue?->campus }}</td>
                    <td>{{ $b->booking_date?->format('d/m/Y') }}</td>
                    <td>{{ $b->start_time }}</td>
                    <td>{{ $b->end_time }}</td>
                    <td>{{ $b->user?->name }}</td>
                    <td>{{ $b->user?->email }}</td>
                    <td>{{ $b->user?->program }}</td>
                    <td>{{ str_replace('_', ' ', $b->purpose) }}</td>
                    <td><span class="status status-{{ $b->status }}">{{ ucfirst($b->status) }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">University Venue Booking System &mdash; Mzumbe University</p>
</body>
</html>
