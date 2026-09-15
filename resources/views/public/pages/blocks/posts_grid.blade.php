@php
    // Which posts and what their cards say come from PostsGrid, which the
    // editor's live preview asks as well — so the two cannot drift apart.
    $s     = \VelaBuild\Core\Services\Blocks\PostsGrid::settings($block->settings ?? []);
    $cards = \VelaBuild\Core\Services\Blocks\PostsGrid::cards(\VelaBuild\Core\Services\Blocks\PostsGrid::posts($s));
    $total = count($cards);

    $layout  = $s['layout'];
    $columns = max(1, min($s['columns'], max(1, $total)));
    // A card without a picture keeps its room only when another card has one;
    // a grid of text-only posts stays the compact grid it always was.
    $withPanels = $s['show_image'] && collect($cards)->contains(fn ($c) => $c['image']) && collect($cards)->contains(fn ($c) => !$c['image']);
    // The first picture is routinely the page's largest paint. A grid that
    // skips posts follows something above it, so its first is not.
    $eagerFirst = $s['skip'] === 0 && $s['source'] === 'latest';

    $classes = 'block-posts-grid block-posts-grid--' . $layout . ' block-posts-grid--' . $s['card_style'] . ' block-posts-grid--ratio-' . $s['ratio'];
    $moving  = $layout === 'slider' && $total > min(4, $columns);
    $perView = min(4, $columns);

    $buttonUrl = $s['button_url'];
    if (preg_match('/^\s*(javascript|data|vbscript):/i', $buttonUrl)) {
        $buttonUrl = '';
    }
    if ($s['button_text'] !== '' && $buttonUrl === '') {
        $buttonUrl = url('/posts');
    }
@endphp
@if($total > 0)
@if($layout === 'slider')
@once
<script src="{{ asset('vendor/vela/js/vela-carousel.js') }}?v={{ is_file(public_path('vendor/vela/js/vela-carousel.js')) ? filemtime(public_path('vendor/vela/js/vela-carousel.js')) : '1' }}" defer></script>
@endonce
<div class="{{ $classes }} block-carousel block-carousel--slide{{ $perView > 1 ? ' block-carousel--multi' : '' }}"
    data-vela-carousel data-autoplay="{{ $s['autoplay'] && $moving ? '1' : '0' }}" data-interval="{{ $s['interval'] }}" data-per-view="{{ $perView }}"
    role="region" aria-roledescription="carousel" aria-label="{{ trans('vela::global.posts_grid') }}"
    style="--carousel-per-view: {{ $perView }};">
    <div class="carousel-viewport">
        <div class="carousel-track">
@foreach($cards as $i => $card)
            <div class="carousel-slide{{ $i < $perView ? ' is-active' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ $total }}">
                @include('vela::public.pages.blocks._post_card', ['card' => $card, 'variant' => 'card', 'eager' => false])
            </div>
@endforeach
        </div>
    </div>
@if($moving)
    <button type="button" class="carousel-arrow carousel-prev" aria-label="{{ trans('vela::global.carousel_previous') }}">&#8249;</button>
    <button type="button" class="carousel-arrow carousel-next" aria-label="{{ trans('vela::global.carousel_next') }}">&#8250;</button>
    <div class="carousel-dots">
@for($d = 0; $d <= $total - $perView; $d++)
        <button type="button" class="carousel-dot{{ $d === 0 ? ' active' : '' }}" aria-label="{{ $d + 1 }}"></button>
@endfor
    </div>
@endif
</div>
@elseif($layout === 'featured' && $total > 1)
<div class="{{ $classes }}">
    @include('vela::public.pages.blocks._post_card', ['card' => $cards[0], 'variant' => 'lead', 'eager' => $eagerFirst])
    <div class="posts-grid-rest">
@foreach(array_slice($cards, 1) as $card)
        @include('vela::public.pages.blocks._post_card', ['card' => $card, 'variant' => 'row', 'eager' => false])
@endforeach
    </div>
</div>
@elseif($layout === 'list')
<div class="{{ $classes }}">
@foreach($cards as $i => $card)
    @include('vela::public.pages.blocks._post_card', ['card' => $card, 'variant' => 'row', 'eager' => $i === 0 && $eagerFirst])
@endforeach
</div>
@else
{{-- grid, and a featured layout with a single post to feature. --}}
<div class="{{ $classes }}{{ $columns > 1 ? ' block-posts-grid--multi' : '' }}" style="grid-template-columns:repeat({{ $columns }},minmax(0,1fr));">
@foreach($cards as $i => $card)
    @include('vela::public.pages.blocks._post_card', ['card' => $card, 'variant' => 'card', 'eager' => $i === 0 && $eagerFirst])
@endforeach
</div>
@endif
@if($s['button_text'] !== '')
<div class="block-posts-grid-footer">
    <a href="{{ $buttonUrl }}" class="block-posts-grid-more"{!! vela_external_link_attrs($buttonUrl) !!}>{{ $s['button_text'] }} <span aria-hidden="true">&rarr;</span></a>
</div>
@endif
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-newspaper',
        'title'   => trans('vela::global.posts_grid_empty_title'),
        'message' => trans('vela::global.posts_grid_empty_message'),
        'ctaText' => trans('vela::global.posts_grid_empty_cta'),
        'ctaUrl'  => route('vela.admin.contents.create'),
    ])
@endif
