@php
    /** @var array{store: string, accent: string, preheader?: string, heading: string, intro?: string, details?: list<array{0: string, 1: string}>, lines?: list<array{0: string, 1: string}>, totals?: list<array{0: string, 1: string}>, button?: array{label: string, url: string}|null, outro?: string|null, footer?: string|null} $content */
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $content['accent'] ?? '') ? $content['accent'] : '#5b3df5';
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $content['heading'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f3f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1c1b29;">
@if (! empty($content['preheader']))
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $content['preheader'] }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f3f8;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                <tr>
                    <td style="padding:0 4px 16px;font-size:18px;font-weight:700;letter-spacing:-0.01em;color:#1c1b29;">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $accent }};margin-right:8px;"></span>{{ $content['store'] }}
                    </td>
                </tr>
                <tr>
                    <td style="background:#ffffff;border-radius:16px;border:1px solid #e8e6f0;overflow:hidden;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="height:4px;background:{{ $accent }};font-size:0;line-height:0;">&nbsp;</td></tr>
                            <tr>
                                <td style="padding:32px 32px 8px;">
                                    <h1 style="margin:0;font-size:24px;line-height:1.25;font-weight:800;letter-spacing:-0.02em;color:#1c1b29;">{{ $content['heading'] }}</h1>
                                    @if (! empty($content['intro']))
                                        <p style="margin:12px 0 0;font-size:15px;line-height:1.6;color:#55536a;">{{ $content['intro'] }}</p>
                                    @endif
                                </td>
                            </tr>

                            @foreach ($content['body'] ?? [] as $paragraph)
                                <tr>
                                    <td style="padding:{{ $loop->first ? '4px' : '0' }} 32px 12px;font-size:15px;line-height:1.65;color:#3d3b50;white-space:pre-line;">{{ $paragraph }}</td>
                                </tr>
                            @endforeach

                            @if (! empty($content['details']))
                                <tr>
                                    <td style="padding:20px 32px 0;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f7fc;border-radius:12px;">
                                            @foreach ($content['details'] as [$label, $value])
                                                <tr>
                                                    <td style="padding:10px 16px;font-size:13px;color:#77758c;">{{ $label }}</td>
                                                    <td align="right" style="padding:10px 16px;font-size:14px;font-weight:600;color:#1c1b29;">{{ $value }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                            @endif

                            @if (! empty($content['lines']) || ! empty($content['totals']))
                                <tr>
                                    <td style="padding:20px 32px 0;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            @foreach ($content['lines'] ?? [] as [$left, $right])
                                                <tr>
                                                    <td style="padding:10px 0;border-bottom:1px solid #eeecf5;font-size:14px;color:#1c1b29;">{{ $left }}</td>
                                                    <td align="right" style="padding:10px 0;border-bottom:1px solid #eeecf5;font-size:14px;color:#55536a;white-space:nowrap;">{{ $right }}</td>
                                                </tr>
                                            @endforeach
                                            @foreach ($content['totals'] ?? [] as $i => [$label, $value])
                                                <tr>
                                                    <td style="padding:{{ ($i === 0 && ! empty($content['lines'])) ? '14px' : '4px' }} 0 4px;font-size:{{ $loop->last ? '16px' : '14px' }};font-weight:{{ $loop->last ? '800' : '500' }};color:{{ $loop->last ? '#1c1b29' : '#55536a' }};">{{ $label }}</td>
                                                    <td align="right" style="padding:{{ ($i === 0 && ! empty($content['lines'])) ? '14px' : '4px' }} 0 4px;font-size:{{ $loop->last ? '16px' : '14px' }};font-weight:{{ $loop->last ? '800' : '500' }};color:#1c1b29;white-space:nowrap;">{{ $value }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                            @endif

                            @if (! empty($content['links']))
                                <tr>
                                    <td style="padding:20px 32px 0;">
                                        @foreach ($content['links'] as [$label, $url])
                                            <a href="{{ $url }}" style="display:block;margin:0 0 10px;padding:12px 16px;border:1px solid #e3e1ee;border-radius:10px;color:{{ $accent }};font-size:14px;font-weight:700;text-decoration:none;">{{ $label }} &rarr;</a>
                                        @endforeach
                                    </td>
                                </tr>
                            @endif

                            @if (! empty($content['button']))
                                <tr>
                                    <td style="padding:28px 32px 4px;">
                                        <a href="{{ $content['button']['url'] }}" style="display:inline-block;background:{{ $accent }};color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;">{{ $content['button']['label'] }}</a>
                                    </td>
                                </tr>
                            @endif

                            <tr>
                                <td style="padding:24px 32px 32px;font-size:13px;line-height:1.6;color:#77758c;">
                                    {{ $content['outro'] ?? '' }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:20px 8px 0;font-size:12px;line-height:1.6;color:#8d8ba0;">
                        {{ $content['footer'] ?? 'Sent by '.$content['store'] }}
                        @if (! empty($content['unsubscribe_url']))<br><a href="{{ $content['unsubscribe_url'] }}" style="color:#8d8ba0;text-decoration:underline;">Unsubscribe from these emails</a>@endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@if (! empty($content['tracking_pixel']))<img src="{{ $content['tracking_pixel'] }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0;">@endif
</body>
</html>
