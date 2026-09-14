@php
    $slides   = array_values(array_filter(
        ($block->content)['slides'] ?? [],
        fn ($s) => is_array($s) && (!empty($s['image_url']) || !empty($s['heading']) || !empty($s['text']))
    ));
    $settings = $block->settings ?? [];
    $total    = count($slides);

    $autoplay   = (bool) ($settings['autoplay'] ?? true);
    $interval   = max(1500, (int) ($settings['interval'] ?? 5000));
    $showArrows = (bool) ($settings['show_arrows'] ?? true);
    $showDots   = (bool) ($settings['show_dots'] ?? true);

    // Several at once only slides; a fade shows one picture over another.
    $perView = min(4, max(1, (int) ($settings['per_view'] ?? 1)));
    $effect  = $perView === 1 && ($settings['effect'] ?? 'slide') === 'fade' ? 'fade' : 'slide';

    // A fixed shape keeps the page still when pictures of different shapes
    // come round. `auto` lets each picture keep its own.
    $ratios = ['16:9' => '16 / 9', '21:9' => '21 / 9', '3:2' => '3 / 2', '4:3' => '4 / 3', '1:1' => '1 / 1', '4:5' => '4 / 5'];
    $ratio  = $ratios[$settings['ratio'] ?? ''] ?? null;

    // One picture at a time: the words sit on it, like a banner. Several: they
    // go under each one, like a row of cards — on a card a third of the width
    // an overlay leaves no picture to see.
    $overlay  = $perView === 1;
    $positions = ['center', 'left', 'bottom-left'];
    $position = in_array($settings['text_position'] ?? '', $positions, true) ? $settings['text_position'] : 'center';

    $label = trim((string) ($settings['label'] ?? '')) ?: trans('vela::global.carousel');
@endphp
@if($total > 0)
@once
<script src="{{ asset('vendor/vela/js/vela-carousel.js') }}?v={{ is_file(public_path('vendor/vela/js/vela-carousel.js')) ? filemtime(public_path('vendor/vela/js/vela-carousel.js')) : '1' }}" defer></script>
@endonce
<div class="block-carousel block-carousel--{{ $effect }}{{ $perView > 1 ? ' block-carousel--multi' : '' }}{{ $ratio ? ' block-carousel--fixed-ratio' : '' }}"
    data-vela-carousel
    data-autoplay="{{ $autoplay && $total > $perView ? '1' : '0' }}"
    data-interval="{{ $interval }}"
    data-per-view="{{ $perView }}"
    role="region" aria-roledescription="carousel" aria-label="{{ $label }}"
    style="--carousel-per-view: {{ $perView }};{{ $ratio ? ' --carousel-ratio: ' . $ratio . ';' : '' }}">
    <div class="carousel-viewport">
        <div class="carousel-track">
@foreach($slides as $i => $slide)
@php
    $heading     = trim((string) ($slide['heading'] ?? ''));
    $text        = trim((string) ($slide['text'] ?? ''));
    $buttonLabel = trim((string) ($slide['button_label'] ?? ''));
    $buttonUrl   = trim((string) ($slide['button_url'] ?? ''));
    $link        = trim((string) ($slide['link'] ?? ''));
    $caption     = trim((string) ($slide['caption'] ?? ''));
    $hasWords    = $heading !== '' || $text !== '' || ($buttonLabel !== '' && $buttonUrl !== '');
    $alt         = $heading ?: $caption;
@endphp
            <div class="carousel-slide{{ $i === 0 ? ' is-active' : '' }}{{ $hasWords && $overlay ? ' has-overlay' : '' }}"
                role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ $total }}"
                @if($i !== 0) aria-hidden="true" @endif>
                <div class="carousel-media">
@if($link !== '')
                    <a href="{{ $link }}" class="carousel-media-link" tabindex="-1">
@endif
@if(!empty($slide['image_url']))
                    {!! vela_image($slide['image_url'], $alt, $perView > 1 ? [400, 640, 960] : [640, 960, 1280, 1920], 'fit', ['class' => 'carousel-image'], $i === 0 ? 'eager' : 'lazy') !!}
@endif
@if($link !== '')
                    </a>
@endif
                </div>
@if($hasWords)
                <div class="carousel-content{{ $overlay ? ' carousel-content--overlay carousel-content--' . $position : ' carousel-content--below' }}">
@if($heading !== '')
                    <h2 class="carousel-heading">{{ $heading }}</h2>
@endif
@if($text !== '')
                    <p class="carousel-text">{!! nl2br(e($text)) !!}</p>
@endif
@if($buttonLabel !== '' && $buttonUrl !== '')
                    <a href="{{ $buttonUrl }}" class="carousel-button">{{ $buttonLabel }}</a>
@endif
                </div>
@endif
@if($caption !== '' && !$hasWords)
                <div class="carousel-caption">{{ $caption }}</div>
@endif
            </div>
@endforeach
        </div>
@if($showArrows && $total > $perView)
        <button type="button" class="carousel-arrow carousel-prev" aria-label="{{ trans('vela::global.carousel_previous') }}">&#8249;</button>
        <button type="button" class="carousel-arrow carousel-next" aria-label="{{ trans('vela::global.carousel_next') }}">&#8250;</button>
@endif
    </div>
@if($showDots && $total > $perView)
    <div class="carousel-dots">
@for($d = 0; $d <= $total - $perView; $d++)
        <button type="button" class="carousel-dot{{ $d === 0 ? ' active' : '' }}" aria-label="{{ $d + 1 }}"@if($d === 0) aria-current="true"@endif></button>
@endfor
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
