@php
    $items = ($block->content)['items'] ?? [];
    $columns = ($block->settings)['columns'] ?? 3;
@endphp
@if(count($items) > 0)
    <div style="display:grid; grid-template-columns:repeat({{ min((int)$columns, 4) }}, 1fr); gap:10px;">
        @foreach($items as $item)
            @if(!empty($item['icon']) || !empty($item['title']))
            <div style="text-align:center; padding:8px;">
                <div><i class="{{ $item['icon'] ?? 'fas fa-star' }}"></i></div>
                @if(!empty($item['title']))
                    <small class="font-weight-bold">{{ $item['title'] }}</small>
                @endif
                @if(!empty($item['description']))
                    <br><small class="text-muted">{{ \Illuminate\Support\Str::limit($item['description'], 60) }}</small>
                @endif
            </div>
            @endif
        @endforeach
    </div>
@else
    <em class="text-muted">{{ trans('vela::global.empty_block') }}</em>
@endif
