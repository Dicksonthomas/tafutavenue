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
            <p>Hello {{ $user->name }},</p>
            <p>Your Class Representative (CR) account has been created. Use the following details to log in to the system:</p>

            <table style="width:100%;border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Email</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $user->email }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Temporary Password</td>
                    <td style="padding:8px 0;font-weight:bold;">{{ $plainPassword }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#64748b;font-size:13px;">Reg No</td>
                    <td style="padding:8px 0;">{{ $user->reg_no }}</td>
                </tr>
            </table>

            <p style="font-size:13px;color:#64748b;">
                For your security, we recommend you change this password as soon as you log in
                (Profile &rarr; Change Password).
            </p>
        </div>
    </div>
</body>
</html>
