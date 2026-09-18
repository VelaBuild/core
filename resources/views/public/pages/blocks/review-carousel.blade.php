@php
    $content = $block->content ?? [];
    $s       = \VelaBuild\Core\Services\Blocks\Reviews::listSettings($block->settings ?? [], 10, false);
    $heading = trim((string) ($content['heading'] ?? ''));
    $reviews = \VelaBuild\Core\Services\Blocks\Reviews::newest($s['min_rating'], $s['max_count']);
    $look    = \VelaBuild\Core\Services\Blocks\Reviews::look('block-review-carousel', $s);
@endphp
@if($reviews->isNotEmpty())
<div class="{{ implode(' ', $look['classes']) }}" style="{{ $look['style'] }}" data-ga-section="reviews">
@if($heading !== '')
    <h2 class="block-review-heading">{{ $heading }}</h2>
@endif
    {{-- A strip that scrolls and snaps, reachable from a keyboard: it is a
         scrolling region, and one a keyboard cannot reach cannot be read. --}}
    <div class="block-review-carousel-strip" tabindex="0" role="region" aria-label="{{ $heading !== '' ? $heading : trans('vela::global.review_strip_label') }}">
@foreach($reviews as $review)
        @include('vela::public.pages.blocks._review_card', ['review' => $review, 's' => $s])
@endforeach
    </div>
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-star',
        'title'   => trans('vela::global.reviews_empty_title'),
        'message' => trans('vela::global.reviews_empty_message'),
        'ctaText' => trans('vela::global.review_manage_cta'),
        'ctaUrl'  => route('vela.admin.tools.reviews'),
    ])
@endif
