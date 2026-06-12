{{ $headline ?? 'Argusly' }}

@if (!empty($intro))
{{ $intro }}

@endif
@if (!empty($body))
{{ $body }}

@endif
@isset($lines)
@foreach ((array) $lines as $line)
@if (trim((string) $line) !== '')
{{ $line }}

@endif
@endforeach
@endisset
@yield('content')
@if (!empty($cta_url))
Action: {{ $cta_label ?? 'Open Argusly' }}
Link: {{ $cta_url }}

@endif
This is a system email from Argusly.
Argusly is a product by Onygo. If you did not expect this message, you can safely ignore it.
