@php
    $items = ($block->content)['items'] ?? [];
    $firstOpen = ($block->settings ?? [])['first_open'] ?? true;
@endphp
<div class="block-accordion" x-data="{ openItem: {{ $firstOpen && count($items) > 0 ? '0' : 'null' }} }">
    @foreach($items as $index => $item)
        <div class="block-accordion-item" :class="{ 'open': openItem === {{ $index }} }">
            <div class="block-accordion-header" @click="openItem = openItem === {{ $index }} ? null : {{ $index }}">
                <span>{{ e($item['title'] ?? '') }}</span>
                <svg class="block-accordion-chevron" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div class="block-accordion-body">
                {!! nl2br(e($item['body'] ?? '')) !!}
            </div>
        </div>
    @endforeach
</div>
