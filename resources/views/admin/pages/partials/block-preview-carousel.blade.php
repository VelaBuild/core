@php
    $slides = ($block->content)['slides'] ?? [];
    $settings = $block->settings ?? [];
    $total = count($slides);
@endphp
@if($total > 0)
    <div style="display:flex; gap:8px; overflow-x:auto;">
        @foreach($slides as $i => $slide)
            <div style="flex:0 0 auto; width:120px; border:1px solid #dee2e6; border-radius:4px; overflow:hidden;">
                @if(!empty($slide['image_url']))
                    <img src="{{ e($slide['image_url']) }}" alt="{{ e($slide['caption'] ?? '') }}" style="width:100%; height:80px; object-fit:cover;">
                @else
                    <div style="width:100%; height:80px; background:#f0f0f0; display:flex; align-items:center; justify-content:center;">
                        <small class="text-muted">{{ $i + 1 }}</small>
                    </div>
                @endif
                @if(!empty($slide['caption']))
                    <div style="padding:4px;"><small>{{ \Illuminate\Support\Str::limit(e($slide['caption']), 30) }}</small></div>
                @endif
            </div>
        @endforeach
    </div>
    <small class="text-muted">{{ $total }} {{ trans_choice('vela::global.slides_count', $total, ['count' => $total]) }}</small>
@else
    <em class="text-muted">{{ trans('vela::global.empty_block') }}</em>
@endif
