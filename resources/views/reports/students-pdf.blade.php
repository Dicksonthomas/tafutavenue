<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 10px; }
        .header { background: #002f3a; color: #ffffff; padding: 14px 18px; margin-bottom: 12px; }
        .header h1 { margin: 0; font-size: 16px; }
        .header p { margin: 4px 0 0; font-size: 10px; color: #cfdadd; }
        .filters { margin-bottom: 12px; font-size: 9px; color: #64748b; }
        .filters span { display: inline-block; background: #f1f5f9; border-radius: 8px; padding: 2px 8px; margin-right: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; padding: 6px 8px; font-size: 8px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; }
        tr:nth-child(even) { background: #fafafa; }
        .footer { margin-top: 16px; font-size: 9px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header">
        <h1>CR List</h1>
        <p>Generated {{ $generatedAt->format('d/m/Y H:i') }} &middot; Total: {{ $users->count() }}</p>
    </div>

    @php
        $filterLabels = array_filter($filters ?? []);
    @endphp
    @if(count($filterLabels) > 0)
        <div class="filters">
            Filters:
            @foreach($filterLabels as $key => $value)
                <span>{{ ucfirst(str_replace('_', ' ', $key)) }}: {{ $value }}</span>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Reg No</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Campus</th>
                <th>Faculty</th>
                <th>Department</th>
                <th>Program</th>
                <th>Level</th>
                <th>Year</th>
                <th>Sex</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $i => $u)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->reg_no }}</td>
                    <td>{{ $u->email }}</td>
                    <td>{{ $u->phone }}</td>
                    <td>{{ $u->campus }}</td>
                    <td>{{ $u->faculty }}</td>
                    <td>{{ $u->department }}</td>
                    <td>{{ $u->program }}</td>
                    <td>{{ $u->level }}</td>
                    <td>{{ $u->year_of_study }}</td>
                    <td>{{ $u->sex === 'male' ? 'Male' : ($u->sex === 'female' ? 'Female' : '') }}</td>
                    <td>{{ $u->is_active ? 'Active' : 'Suspended' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">University Venue Booking System &mdash; Mzumbe University</p>
</body>
</html>
