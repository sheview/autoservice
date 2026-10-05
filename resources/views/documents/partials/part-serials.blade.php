{{--
    The serial numbers that went out on one request line, as written when handed out
    (IssuedPartSerials): one per box, wrapping onto as many lines as it takes; those taken back
    since are struck through.
--}}
@php($returned = collect($serials['returned'] ?? [])->pluck('unit_id')->all())
@if (! empty($serials['out']))
    <div class="sn-list">
        @foreach ($serials['out'] as $piece)
            <span class="sn{{ in_array($piece['unit_id'], $returned, true) ? ' sn-back' : '' }}">{{ $piece['serial'] }}</span>
        @endforeach
    </div>
    @if ($returned !== [])
        <div class="muted" style="font-size: 8.5pt">{{ __('document.serials.returned_note') }}</div>
    @endif
@endif
