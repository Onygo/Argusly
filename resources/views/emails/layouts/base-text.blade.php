{{ $headline ?? 'PublishLayer' }}

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
Action: {{ $cta_label ?? 'Open PublishLayer' }}
Link: {{ $cta_url }}

@endif
This is a system email from PublishLayer.
If you did not expect this message, you can safely ignore it.

