@php
    $images = ($block->content)['images'] ?? [];
    $settings = $block->settings ?? [];
    $columns = (int)($settings['columns'] ?? 3);
    $total = count($images);
@endphp
@if($total > 0)
    <div style="display:grid; grid-template-columns:repeat({{ min($columns, 4) }}, 1fr); gap:6px;">
        @foreach(array_slice($images, 0, 8) as $img)
            @if(!empty($img['url']))
                <img src="{{ e($img['url']) }}" alt="{{ e($img['alt'] ?? '') }}" style="width:100%; height:60px; object-fit:cover; border-radius:3px;">
            @endif
        @endforeach
    </div>
    @if($total > 8)
        <small class="text-muted">+{{ $total - 8 }} more</small>
    @endif
@else
    <em class="text-muted">{{ trans('vela::global.empty_block') }}</em>
@endif
