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
    <div class="icon-box--{{ $layout }}">
        <div class="icon-box-icon">
            <i class="{{ $item['icon'] ?? 'fas fa-star' }}"></i>
        </div>
@if(!empty($item['title']))
        <p class="icon-box-title">{{ $item['title'] }}</p>
@endif
@if(!empty($item['description']))
        <p class="icon-box-description">{{ $item['description'] }}</p>
@endif
    </div>
@endif
@endforeach
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-th-large',
        'title'   => trans('vela::global.icon_box_empty_title'),
        'message' => trans('vela::global.icon_box_empty_message'),
    ])
@endif
