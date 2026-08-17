<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site recovered</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2933;">
    <div style="max-width:560px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e4e7eb;">
            <div style="background:#027a48;padding:20px 24px;">
                <h1 style="margin:0;font-size:18px;color:#ffffff;">✅ Site back online</h1>
            </div>
            <div style="padding:24px;">
                <p style="margin:0 0 16px;font-size:15px;">
                    Good news — MarQira Pulse is receiving heartbeats from your site again. It is
                    now <strong>online</strong>.
                </p>

                <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px;">
                    <tr>
                        <td style="padding:6px 0;color:#616e7c;width:44%;">Site</td>
                        <td style="padding:6px 0;font-weight:600;">{{ $site->domain ?? $site->domain_normalized ?? '—' }}</td>
                    </tr>
                    @if ($site->home_url)
                    <tr>
                        <td style="padding:6px 0;color:#616e7c;">URL</td>
                        <td style="padding:6px 0;"><a href="{{ $site->home_url }}" style="color:#2563eb;">{{ $site->home_url }}</a></td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding:6px 0;color:#616e7c;">Recovered at</td>
                        <td style="padding:6px 0;">{{ $recoveredAt ? $recoveredAt->toDayDateTimeString() . ' UTC' : now()->toDayDateTimeString() . ' UTC' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:#616e7c;">Offline since</td>
                        <td style="padding:6px 0;">{{ $offlineSince ? $offlineSince->toDayDateTimeString() . ' UTC' : '—' }}</td>
                    </tr>
                    @if ($alertsSent > 0)
                    <tr>
                        <td style="padding:6px 0;color:#616e7c;">Alerts during outage</td>
                        <td style="padding:6px 0;">{{ $alertsSent }}</td>
                    </tr>
                    @endif
                </table>

                <p style="margin:0;font-size:14px;color:#616e7c;">
                    No action is needed. MarQira Pulse will keep monitoring your site.
                </p>
            </div>
            <div style="padding:16px 24px;background:#f9fafb;border-top:1px solid #e4e7eb;">
                <p style="margin:0;font-size:12px;color:#9aa5b1;">
                    Sent by MarQira Pulse · automated monitoring alert
                </p>
            </div>
        </div>
    </div>
</body>
</html>
