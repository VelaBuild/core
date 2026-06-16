@php
    $items = ($block->content)['items'] ?? [];
@endphp
@if(count($items) > 0)
    @foreach($items as $item)
        <div class="preview-accordion-item">
            <div class="preview-accordion-header">{{ $item['title'] ?? $item['question'] ?? $item['heading'] ?? $item['label'] ?? '' }}</div>
            <div class="preview-accordion-body">{!! nl2br(e($item['body'] ?? $item['answer'] ?? $item['content'] ?? $item['text'] ?? '')) !!}</div>
        </div>
    @endforeach
@else
    <em class="text-muted">{{ trans('vela::global.no_accordion_items') }}</em>
@endif
