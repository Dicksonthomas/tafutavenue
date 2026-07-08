<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 13px; }
        .header { background: #002f3a; color: #ffffff; padding: 14px 18px; margin-bottom: 12px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header p { margin: 4px 0 0; font-size: 13px; color: #cfdadd; }
        .filters { margin-bottom: 12px; font-size: 12px; color: #64748b; }
        .filters span { display: inline-block; background: #f1f5f9; border-radius: 8px; padding: 3px 9px; margin-right: 6px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th { background: #f1f5f9; text-align: left; padding: 7px 8px; font-size: 11px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; word-wrap: break-word; }
        td { padding: 7px 8px; font-size: 12px; border-bottom: 1px solid #f1f5f9; word-wrap: break-word; overflow-wrap: break-word; }
        tr:nth-child(even) { background: #fafafa; }
        .footer { margin-top: 16px; font-size: 12px; color: #94a3b8; }
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
                <th style="width: 3%;">#</th>
                <th style="width: 12%;">Name</th>
                <th style="width: 8%;">Reg No</th>
                <th style="width: 17%;">Email</th>
                <th style="width: 8%;">Phone</th>
                <th style="width: 7%;">Campus</th>
                <th style="width: 6%;">Faculty</th>
                <th style="width: 9%;">Department</th>
                <th style="width: 11%;">Program</th>
                <th style="width: 6%;">Level</th>
                <th style="width: 3%;">Year</th>
                <th style="width: 4%;">Sex</th>
                <th style="width: 6%;">Status</th>
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
