@once
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
@endonce
@php
    $items = ($block->content)['items'] ?? [];
    $columns = ($block->settings)['columns'] ?? 3;
    $layout = ($block->settings)['layout'] ?? 'vertical';
@endphp
@if(count($items) > 0)
<div class="block-icon-boxes" style="display:grid;grid-template-columns:repeat({{ (int)$columns }},1fr);gap:20px;">
    @foreach($items as $item)
        @if(!empty($item['icon']) || !empty($item['title']))
        <div class="icon-box--{{ e($layout) }}">
            <div class="icon-box-icon">
                <i class="{{ e($item['icon'] ?? 'fas fa-star') }}"></i>
            </div>
            @if(!empty($item['title']))
                <p class="icon-box-title">{{ e($item['title']) }}</p>
            @endif
            @if(!empty($item['description']))
                <p class="icon-box-description">{{ e($item['description']) }}</p>
            @endif
        </div>
        @endif
    @endforeach
</div>
@endif
