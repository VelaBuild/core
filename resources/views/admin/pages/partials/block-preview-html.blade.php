@php
    $html = $block->content['html'] ?? '';
@endphp
@if($html)
    <div style="border:1px solid #e9ecef; border-radius:4px; padding:8px; background:#fafafa;">
        {!! $html !!}
    </div>
@else
    <em class="text-muted">{{ trans('vela::global.empty_html_block') }}</em>
@endif
