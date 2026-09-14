@php
    // The block declares its list as `items`, like every other list block, and
    // that is what list_block_types advertises — this view read `testimonials`,
    // so content sent the documented way rendered as the empty state instead.
    //
    // And the block editor went on saving `testimonials` after that, so
    // everything added through its dialog saved and never appeared. A block
    // stored that way is still read, until it is next saved as `items`.
    $content      = $block->content ?? [];
    // Read with Arr::get on purpose: `testimonials` is left out of the
    // registered defaults so nothing is invited to write it again, and the
    // check that every content key a view reads is declared would
    // otherwise require it.
    $testimonials = !empty($content['items']) ? $content['items'] : \Illuminate\Support\Arr::get($content, 'testimonials', []);
    $testimonials = is_array($testimonials) ? $testimonials : [];
    $testimonials = array_values(array_filter($testimonials, fn ($t) => is_array($t) && (!empty($t['quote']) || !empty($t['name']))));

    $settings = $block->settings ?? [];
    // grid: the cards side by side, as it always was — `cards` is what the
    // editor saved for that before there was a second choice.
    // slider: moved through by public/js/vela-carousel.js, the carousel's own.
    $layout   = ($settings['layout'] ?? 'grid') === 'slider' ? 'slider' : 'grid';
    $perView  = min(3, max(1, (int) ($settings['per_view'] ?? 1)));
    $autoplay = (bool) ($settings['autoplay'] ?? true);
    $interval = max(2000, (int) ($settings['interval'] ?? 6000));
    $total    = count($testimonials);
    $moving   = $layout === 'slider' && $total > $perView;
@endphp
@if($total > 0)
@if($layout === 'slider')
@once
<script src="{{ asset('vendor/vela/js/vela-carousel.js') }}?v={{ is_file(public_path('vendor/vela/js/vela-carousel.js')) ? filemtime(public_path('vendor/vela/js/vela-carousel.js')) : '1' }}" defer></script>
@endonce
<div class="block-testimonials block-testimonials--slider block-carousel block-carousel--slide{{ $perView > 1 ? ' block-carousel--multi' : ' block-testimonials--single' }}"
    data-vela-carousel data-autoplay="{{ $autoplay && $moving ? '1' : '0' }}" data-interval="{{ $interval }}" data-per-view="{{ $perView }}"
    role="region" aria-roledescription="carousel" aria-label="{{ trans('vela::global.testimonials') }}"
    style="--carousel-per-view: {{ $perView }};">
    <div class="carousel-viewport">
        <div class="carousel-track">
@foreach($testimonials as $i => $t)
            <div class="carousel-slide{{ $i < $perView ? ' is-active' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ $total }}">
                @include('vela::public.pages.blocks._testimonial_card', ['t' => $t])
            </div>
@endforeach
        </div>
    </div>
@if($moving)
    {{-- Outside the viewport, unlike the carousel's: beside a quote they
         would cover its words, and inside they are clipped with the slides. --}}
    <button type="button" class="carousel-arrow carousel-prev" aria-label="{{ trans('vela::global.carousel_previous') }}">&#8249;</button>
    <button type="button" class="carousel-arrow carousel-next" aria-label="{{ trans('vela::global.carousel_next') }}">&#8250;</button>
@endif
@if($moving)
    <div class="carousel-dots">
@for($d = 0; $d <= $total - $perView; $d++)
        <button type="button" class="carousel-dot{{ $d === 0 ? ' active' : '' }}" aria-label="{{ $d + 1 }}"></button>
@endfor
    </div>
@endif
</div>
@else
<div class="block-testimonials block-testimonials--grid">
@foreach($testimonials as $t)
    @include('vela::public.pages.blocks._testimonial_card', ['t' => $t])
@endforeach
</div>
@endif
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-comment-dots',
        'title'   => trans('vela::global.testimonials_empty_title'),
        'message' => trans('vela::global.testimonials_empty_message'),
    ])
@endif
