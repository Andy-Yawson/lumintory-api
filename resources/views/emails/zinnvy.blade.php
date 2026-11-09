{{-- The Zinnvy email layout. One design for every product: copy this file, ZinnvyMail.php and zinnvy-mark.png together (see README). --}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f2ec;-webkit-text-size-adjust:100%;">
@if (!empty($preheader))
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f4f2ec;font-size:1px;line-height:1px;">{{ $preheader }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f2ec;">
<tr><td align="center" style="padding:28px 14px;">
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
    {{-- Header --}}
    <tr><td style="padding:0 6px 16px 6px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="vertical-align:middle;"><img src="{{ $logo }}" width="36" height="36" alt="Zinnvy" style="display:block;border:0;width:36px;height:36px;"></td>
        <td style="vertical-align:middle;padding-left:10px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:17px;font-weight:700;color:#16231c;letter-spacing:-0.2px;">Zinnvy <span style="font-weight:500;color:#6b7a72;">{{ $product }}</span></td>
      </tr></table>
    </td></tr>
    {{-- Card --}}
    <tr><td style="background:#ffffff;border-radius:16px;border-top:4px solid #1f5a45;box-shadow:0 1px 3px rgba(22,35,28,.08);">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:34px 36px 30px 36px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:15px;line-height:24px;color:#2c3a33;">
        @if (!empty($from))
        <div style="margin:0 0 10px 0;font-size:12px;line-height:16px;letter-spacing:.6px;text-transform:uppercase;color:#6b7a72;">{{ $from }}</div>
        @endif
        <h1 style="margin:0 0 18px 0;font-size:22px;line-height:30px;font-weight:700;color:#16231c;letter-spacing:-0.3px;">{{ $title }}</h1>
        @foreach ($blocks as $b)
          @if ($b['type'] === 'p')
            <p style="margin:0 0 16px 0;">{!! $b['html'] !!}</p>
          @elseif ($b['type'] === 'ul')
            <ul style="margin:0 0 16px 0;padding:0 0 0 20px;">@foreach ($b['items'] as $i)<li style="margin:0 0 6px 0;">{!! $i !!}</li>@endforeach</ul>
          @elseif ($b['type'] === 'kv')
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px 0;background:#f6f8f6;border-radius:12px;"><tr><td style="padding:14px 18px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              @foreach ($b['rows'] as [$k, $v])
                <tr><td style="padding:5px 12px 5px 0;vertical-align:top;font-size:13px;color:#6b7a72;white-space:nowrap;">{{ $k }}</td><td style="padding:5px 0;vertical-align:top;font-size:14px;color:#16231c;font-weight:600;">{!! $v !!}</td></tr>
              @endforeach
              </table>
            </td></tr></table>
          @elseif ($b['type'] === 'button')
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px 0;"><tr><td style="background:#1f5a45;border-radius:10px;"><a href="{{ $b['url'] }}" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;">{{ $b['label'] }}</a></td></tr></table>
          @elseif ($b['type'] === 'note')
            <p style="margin:0 0 16px 0;padding:12px 16px;background:#fbf3e4;border-radius:10px;font-size:13px;line-height:20px;color:#7a5413;">{!! $b['html'] !!}</p>
          @endif
        @endforeach
      </td></tr></table>
    </td></tr>
    {{-- Footer --}}
    <tr><td style="padding:20px 10px 0 10px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;line-height:19px;color:#7d8a83;text-align:center;">
      @if (!empty($reason))<p style="margin:0 0 8px 0;">{{ $reason }}</p>@endif
      <p style="margin:0;">Sent by <a href="https://zinnvy.com" style="color:#1f5a45;text-decoration:none;font-weight:600;">Zinnvy</a>, a product of Zinnvytech, Accra, Ghana.<br>
      Need help? <a href="mailto:support@zinnvy.com" style="color:#1f5a45;text-decoration:none;">support@zinnvy.com</a></p>
    </td></tr>
  </table>
</td></tr>
</table>
</body>
</html>
