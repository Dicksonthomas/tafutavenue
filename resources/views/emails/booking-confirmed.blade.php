<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:24px;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
        <div style="background:#002f3a;padding:20px 24px;">
            <h1 style="color:#ffffff;font-size:18px;margin:0;">Booking Yako Imefanikiwa</h1>
        </div>
        <div style="padding:24px;color:#1e293b;">
            <p>Habari {{ $booking->user->name }},</p>
            <p>Ombi lako la kubook venue limetumwa kwa mafanikio na linasubiri idhini ya Admin. Taarifa za booking:</p>

            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Venue</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $booking->venue->name }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Tarehe</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $booking->booking_date->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Muda</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $booking->start_time }} - {{ $booking->end_time }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Lengo</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $booking->title ?: ucfirst(str_replace('_', ' ', $booking->purpose)) }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Status</td>
                    <td style="padding:8px 0;font-weight:bold;">Inasubiri Idhini (Pending)</td>
                </tr>
            </table>

            <p style="font-size:13px;color:#64748b;">
                Utapata email nyingine mara Admin atakapoidhinisha au kukataa ombi hili.
            </p>
        </div>
    </div>
</body>
</html>
