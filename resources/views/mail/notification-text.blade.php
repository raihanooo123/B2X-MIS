{{-- 05.12 §13: plain-text twin of mail/notification.blade.php. Unescaped: plain text is not HTML. --}}
{!! $mail->heading !!}

@foreach ($mail->paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach ($mail->facts as $fact)
{!! $fact['label'] !!}: {!! $fact['value'] !!}
@endforeach
@if ($mail->actionUrl !== null && $mail->actionLabel !== null)

{!! $mail->actionLabel !!}: {!! $mail->actionUrl !!}
@endif
@if ($mail->closing !== [])

@foreach ($mail->closing as $line)
{!! $line !!}
@endforeach
@endif
