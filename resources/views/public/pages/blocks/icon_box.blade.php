@once
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
@endonce
@php
    // A box with neither an icon nor a title draws nothing, so it does not
    // take a column either.
    $items = array_values(array_filter(($block->content)['items'] ?? [], function ($item) {
        return is_array($item) && (!empty($item['icon']) || !empty($item['title']));
    }));
    $settings = $block->settings ?? [];
    $pick = function (string $key, array $allowed) use ($settings) {
        return in_array($settings[$key] ?? null, $allowed, true) ? $settings[$key] : $allowed[0];
    };
    $total = count($items);

    // Never more columns than boxes: three columns for two boxes left an
    // empty third and the boxes sat to one side. A slider shows this many at
    // a time.
    $columns = max(1, min(6, (int) ($settings['columns'] ?? 3) ?: 3, $total));
    $layout  = $pick('layout', ['vertical', 'horizontal']);
    // plain is the bare icon and words every block saved before there was a
    // choice gets; soft and outline put each box on a card.
    $style   = $pick('card_style', ['plain', 'soft', 'outline']);
    $shape   = $pick('icon_shape', ['none', 'circle', 'square']);
    // grid: side by side, as it always was. slider: moved through by
    // public/js/vela-carousel.js, the carousel's own.
    $display = $pick('display', ['grid', 'slider']);
    $moving  = $display === 'slider' && $total > $columns;
    $autoplay = (bool) ($settings['autoplay'] ?? false);
    $interval = max(2000, (int) ($settings['interval'] ?? 5000));

    // Empty follows the theme's accent; a shape behind the icon is a pale
    // tint of the same colour, so there is no second colour to get wrong.
    $iconColour = \VelaBuild\Core\Services\DesignTokens::colour($settings['icon_color'] ?? null);
    $colourCss  = $iconColour ? '--ib-icon:' . $iconColour . ';' : '';
    $classes    = 'block-icon-boxes block-icon-boxes--' . $style . ' icon-shape--' . $shape;
@endphp
@if($total > 0)
@if($display === 'slider')
@once
<script src="{{ asset('vendor/vela/js/vela-carousel.js') }}?v={{ is_file(public_path('vendor/vela/js/vela-carousel.js')) ? filemtime(public_path('vendor/vela/js/vela-carousel.js')) : '1' }}" defer></script>
@endonce
<div class="{{ $classes }} block-icon-boxes--slider block-carousel block-carousel--slide{{ $columns > 1 ? ' block-carousel--multi' : '' }}"
    data-vela-carousel data-autoplay="{{ $autoplay && $moving ? '1' : '0' }}" data-interval="{{ $interval }}" data-per-view="{{ $columns }}"
    role="region" aria-roledescription="carousel" aria-label="{{ trans('vela::global.icon_box') }}"
    style="--carousel-per-view: {{ $columns }};{{ $colourCss }}">
    <div class="carousel-viewport">
        <div class="carousel-track">
@foreach($items as $i => $item)
            <div class="carousel-slide{{ $i < $columns ? ' is-active' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ $total }}">
                @include('vela::public.pages.blocks._icon_box_item', ['item' => $item, 'layout' => $layout])
            </div>
@endforeach
        </div>
    </div>
@if($moving)
    <button type="button" class="carousel-arrow carousel-prev" aria-label="{{ trans('vela::global.carousel_previous') }}">&#8249;</button>
    <button type="button" class="carousel-arrow carousel-next" aria-label="{{ trans('vela::global.carousel_next') }}">&#8250;</button>
    <div class="carousel-dots">
@for($d = 0; $d <= $total - $columns; $d++)
        <button type="button" class="carousel-dot{{ $d === 0 ? ' active' : '' }}" aria-label="{{ $d + 1 }}"></button>
@endfor
    </div>
@endif
</div>
@else
<div class="{{ $classes }}" style="display:grid;grid-template-columns:repeat({{ $columns }},1fr);gap:20px;{{ $colourCss }}">
@foreach($items as $item)
    @include('vela::public.pages.blocks._icon_box_item', ['item' => $item, 'layout' => $layout])
@endforeach
</div>
@endif
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-th-large',
        'title'   => trans('vela::global.icon_box_empty_title'),
        'message' => trans('vela::global.icon_box_empty_message'),
    ])
@endif
