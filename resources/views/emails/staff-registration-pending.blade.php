<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:24px;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
        <div style="background:#002d62;padding:20px 24px;">
            <h1 style="color:#ffffff;font-size:18px;margin:0;">University Venue Booking System</h1>
        </div>
        <div style="padding:24px;color:#1e293b;">
            <p>A new Staff account is waiting for your approval:</p>

            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Name</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $staff->name }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Email</td>
                    <td style="padding:8px 0;">{{ $staff->email }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Staff ID</td>
                    <td style="padding:8px 0;">{{ $staff->staff_id }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Campus</td>
                    <td style="padding:8px 0;">{{ $staff->campus }}</td>
                </tr>
            </table>

            <p style="font-size:13px;color:#64748b;">
                They cannot sign in until an Admin approves their account from the Staff page.
            </p>
        </div>
    </div>
</body>
</html>
