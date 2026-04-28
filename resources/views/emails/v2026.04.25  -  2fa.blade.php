<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>2FA Code</title>
</head>
<body style="margin:0;padding:0;background:#eaf0f7;font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';color:#2a3b52;">
@php
  $appName = $context['app_name'] ?? config('app.name', 'Velodata Dashboard');
  $recipientName = $context['recipient_name'] ?? 'there';
  $recipientEmail = $context['recipient_email'] ?? null;
  $expiresInMinutes = $context['expires_in_minutes'] ?? 10;
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eaf0f7;padding:26px 12px;">
  <tr>
    <td align="center">
      @include('emails.header')

      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:540px;background:#ffffff;border-radius:8px;border:1px solid #e0e6f0;">
        <tr>
          <td style="padding:26px 28px 8px 28px;font-size:16px;line-height:1.6;">
            Hello {{ $recipientName }},
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px 8px 28px;font-size:16px;line-height:1.6;">
            To complete your login to the Velodata Dashboard, please enter the following code:
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:18px 28px 18px 28px;">
            <div style="display:inline-block;background:#f1f3f7;border:1px solid #d8dfeb;border-radius:8px;padding:16px 26px;">
              <span style="font-size:32px;letter-spacing:8px;font-weight:700;color:#2b3d55;">{{ $code }}</span>
            </div>
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px 24px 28px;font-size:16px;line-height:1.6;">
            This code will expire in {{ $expiresInMinutes }} minutes. If you did not request this code, please ignore this email.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
