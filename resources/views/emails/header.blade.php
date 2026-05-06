@php
  $brandLogoUrl = 'https://laravel.com/img/notification-logo.png';
  $appName = $context['app_name'] ?? config('app.name', 'Velodata Dashboard');
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 auto 18px auto;max-width:540px;">
  <tr>
    <td align="center" style="padding-bottom:14px;">
      <img src="{{ $brandLogoUrl }}" alt="Laravel Logo" style="width:72px;height:72px;object-fit:contain;">
    </td>
  </tr>
  <tr>
    <td style="padding:0;">
      <div style="background:linear-gradient(90deg,#1f6fd9,#2e86f5);border-radius:8px;padding:18px 22px;">
        <div style="font-size:32px;line-height:1; color:#ffffff; font-weight:700;">Your Velodata Sign-In Code</div>
        <div style="margin-top:8px;font-size:15px;line-height:1.4;color:#ffffff;font-weight:600;">Two Factor Authentication</div>
      </div>
    </td>
  </tr>
</table>
