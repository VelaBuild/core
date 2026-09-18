@php
    $content = $block->content ?? [];
    $s       = \VelaBuild\Core\Services\Blocks\Reviews::listSettings($block->settings ?? [], 12, true);
    $heading = trim((string) ($content['heading'] ?? ''));
    $reviews = \VelaBuild\Core\Services\Blocks\Reviews::newest($s['min_rating'], $s['max_count']);
    $look    = \VelaBuild\Core\Services\Blocks\Reviews::look('block-review-grid', $s);
    // The count is a number, not a class: the grid drops to fewer columns as
    // the screen narrows, which a fixed repeat(3) never did.
    $look['style'] .= '--review-columns:' . $s['columns'] . ';';
@endphp
@if($reviews->isNotEmpty())
<div class="{{ implode(' ', $look['classes']) }}" style="{{ $look['style'] }}" data-ga-section="reviews">
@if($heading !== '')
    <h2 class="block-review-heading">{{ $heading }}</h2>
@endif
    <div class="block-review-grid-items">
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
