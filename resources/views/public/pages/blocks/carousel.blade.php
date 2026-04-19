@php
    $slides     = ($block->content)['slides'] ?? [];
    $settings   = $block->settings ?? [];
    $autoplay   = $settings['autoplay'] ?? true;
    $interval   = $settings['interval'] ?? 5000;
    $showArrows = $settings['show_arrows'] ?? true;
    $showDots   = $settings['show_dots'] ?? true;
    $total      = count($slides);
@endphp
@if($total > 0)
<div class="block-carousel"
    x-data="{
        current: 0,
        total: {{ $total }},
        autoplay: {{ $autoplay ? 'true' : 'false' }},
        interval: {{ (int)$interval }},
        prev() { this.current = (this.current - 1 + this.total) % this.total; },
        next() { this.current = (this.current + 1) % this.total; }
    }"
    x-init="if (autoplay) { setInterval(() => next(), interval); }">
    <div class="carousel-track">
@foreach($slides as $i => $slide)
        <div class="carousel-slide" x-show="current === {{ $i }}" style="transition: opacity 0.4s;">
@if(!empty($slide['link']))
            <a href="{{ e($slide['link']) }}">
@endif
@if(!empty($slide['image_url']))
            {!! vela_image($slide['image_url'], $slide['caption'] ?? '', [640, 960, 1280, 1920], 'fit', ['style' => 'width:100%;display:block;']) !!}
@endif
@if(!empty($slide['link']))
            </a>
@endif
@if(!empty($slide['caption']))
            <div class="carousel-caption">{{ e($slide['caption']) }}</div>
@endif
        </div>
@endforeach
@if($showArrows && $total > 1)
        <button class="carousel-arrow carousel-prev" @click="prev()" aria-label="Previous">&#8249;</button>
        <button class="carousel-arrow carousel-next" @click="next()" aria-label="Next">&#8250;</button>
@endif
    </div>
@if($showDots && $total > 1)
    <div class="carousel-dots">
@foreach($slides as $i => $slide)
        <button class="carousel-dot" :class="{ 'active': current === {{ $i }} }" @click="current = {{ $i }}" aria-label="Slide {{ $i + 1 }}"></button>
@endforeach
    </div>
@endif
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-images',
        'title'   => trans('vela::global.carousel_empty_title'),
        'message' => trans('vela::global.carousel_empty_message'),
    ])
@endif
