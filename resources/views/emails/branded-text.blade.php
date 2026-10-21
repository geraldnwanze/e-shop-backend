{!! $content['store'] !!}
{!! str_repeat('=', max(strlen($content['store']), 12)) !!}

{!! $content['heading'] !!}
@if (! empty($content['intro']))

{!! $content['intro'] !!}
@endif
@foreach ($content['body'] ?? [] as $paragraph)

{!! $paragraph !!}
@endforeach
@foreach ($content['details'] ?? [] as [$label, $value])
{!! $label !!}: {!! $value !!}
@endforeach
@if (! empty($content['lines']))

@foreach ($content['lines'] as [$left, $right])
- {!! $left !!}{!! $right !== '' ? ' ('.$right.')' : '' !!}
@endforeach
@endif
@foreach ($content['totals'] ?? [] as [$label, $value])
{!! $label !!}: {!! $value !!}
@endforeach
@foreach ($content['links'] ?? [] as [$label, $url])
{!! $label !!}: {!! $url !!}
@endforeach
@if (! empty($content['button']))

{!! $content['button']['label'] !!}: {!! $content['button']['url'] !!}
@endif
@if (! empty($content['outro']))

{!! $content['outro'] !!}
@endif
@if (! empty($content['unsubscribe_url']))

Unsubscribe from these emails: {!! $content['unsubscribe_url'] !!}
@endif
