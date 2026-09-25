{{-- 05.12 §13: the shared HTML layout for every notification. Mail only (CLAUDE.md). --}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mail->subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;color:#18181b;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
    <tr>
        <td align="center" style="padding:24px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;">
                <tr>
                    <td style="padding:32px 32px 8px;">
                        <h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#18181b;">{{ $mail->heading }}</h1>
                        @foreach ($mail->paragraphs as $paragraph)
                            <p style="margin:0 0 16px;">{{ $paragraph }}</p>
                        @endforeach
                    </td>
                </tr>
                @if ($mail->facts !== [])
                    <tr>
                        <td style="padding:0 32px 16px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e4e4e7;">
                                @foreach ($mail->facts as $fact)
                                    <tr>
                                        <th scope="row" align="left" style="padding:8px 16px 8px 0;border-bottom:1px solid #e4e4e7;font-weight:normal;color:#52525b;">{{ $fact['label'] }}</th>
                                        <td align="right" style="padding:8px 0;border-bottom:1px solid #e4e4e7;font-weight:bold;">{{ $fact['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif
                @if ($mail->actionUrl !== null && $mail->actionLabel !== null)
                    <tr>
                        <td style="padding:8px 32px 24px;">
                            <a href="{{ $mail->actionUrl }}" style="display:inline-block;padding:12px 20px;background:#18181b;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;">{{ $mail->actionLabel }}</a>
                        </td>
                    </tr>
                @endif
                @if ($mail->closing !== [])
                    <tr>
                        <td style="padding:0 32px 32px;color:#52525b;font-size:14px;">
                            @foreach ($mail->closing as $line)
                                <p style="margin:0 0 8px;">{{ $line }}</p>
                            @endforeach
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
</body>
</html>
