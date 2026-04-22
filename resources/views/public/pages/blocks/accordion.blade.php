@php
    $items = ($block->content)['items'] ?? [];
    $firstOpen = ($block->settings ?? [])['first_open'] ?? true;
@endphp
<div class="block-accordion">
@foreach($items as $index => $item)
    <details class="block-accordion-item"@if($firstOpen && $index === 0) open @endif>
        <summary class="block-accordion-header">
            <span>{{ $item['title'] ?? '' }}</span>
            <svg class="block-accordion-chevron" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </summary>
        <div class="block-accordion-body">
            {!! nl2br(e($item['body'] ?? '')) !!}
        </div>
    </details>
@endforeach
</div>
