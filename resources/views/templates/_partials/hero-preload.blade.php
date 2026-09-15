{{-- Preload the first row's background image.

     A row background is a CSS `url()`, which the browser cannot discover until
     the stylesheet has downloaded and parsed. On a page that opens with a
     full-bleed hero that image is the largest paint, so the late discovery
     lands directly on LCP — measured at 87 on corporate and 89 on dark, from
     97-99 without one.

     This pushes onto the `head` stack, which works because a view that
     @extends a layout is rendered before the layout is: by the time
     @stack('head') is reached, this is already on it. The same push from a
     partial the layout itself includes would be too late. --}}
@php
    $velaHeroRow = $page->rows->first() ?? null;
    $velaHeroBlock = $velaHeroRow?->blocks->first();
    // A hero block's picture is an <img> with fetchpriority="high" in the
    // markup, found by the parser without waiting on CSS — and at the width
    // that fits the screen, which a preload of one URL would not match.
    $velaHeroBg = $velaHeroRow?->background_image
        ?: ($velaHeroBlock && $velaHeroBlock->type !== 'hero' ? $velaHeroBlock->background_image : null);
@endphp
@if($velaHeroBg)
@push('head')
    <link rel="preload" as="image" href="{{ vela_background_url($velaHeroBg) }}" fetchpriority="high">
@endpush
@endif
