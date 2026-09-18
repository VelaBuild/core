@php
    $content = $block->content ?? [];
    $s       = \VelaBuild\Core\Services\Blocks\Reviews::summarySettings($block->settings ?? []);
    $heading = trim((string) ($content['heading'] ?? ''));
    $note    = trim((string) ($content['note'] ?? ''));
    $btnText = trim((string) ($content['button_text'] ?? ''));
    $btnUrl  = trim((string) ($content['button_url'] ?? ''));

    // Counted by the database: a site with thousands of reviews used to load
    // every one of them to work out an average.
    $totals  = \VelaBuild\Core\Services\Blocks\Reviews::totals($s['min_rating']);
    $look    = \VelaBuild\Core\Services\Blocks\Reviews::look('block-review-summary', $s);
    $look['classes'][] = 'block-review-summary--btn-' . $s['button_style'];
    $jsonLd  = \VelaBuild\Core\Services\Blocks\Reviews::aggregateRatingJsonLd($totals, $s['min_rating']);
@endphp
@if($totals['count'] > 0)
<div class="{{ implode(' ', $look['classes']) }}" style="{{ $look['style'] }}" data-ga-section="reviews">
@if($heading !== '')
    <h2 class="block-review-summary-heading">{{ $heading }}</h2>
@endif
    <div class="block-review-summary-rating">
        {!! \VelaBuild\Core\Services\Blocks\Reviews::stars($totals['average'], trans('vela::global.review_rating_label', ['rating' => $totals['average']])) !!}
        <div class="block-review-summary-text">
            <strong class="block-review-summary-score">{{ number_format($totals['average'], 1) }}</strong>
            <span class="block-review-summary-outof">{{ trans('vela::global.review_out_of_five') }}</span>
@if($s['show_count'])
            <span class="block-review-summary-count">{{ trans_choice('vela::global.review_count', $totals['count'], ['count' => number_format($totals['count'])]) }}</span>
@endif
        </div>
    </div>
@if($note !== '')
    <p class="block-review-summary-note">{{ $note }}</p>
@endif
@if($btnText !== '' && $btnUrl !== '')
    <a href="{{ $btnUrl }}" class="block-review-summary-btn"{!! vela_external_link_attrs($btnUrl) !!}>{{ $btnText }}</a>
@endif
    {{-- The stars a search result can show, from the same numbers. --}}
@if($jsonLd)
    <script type="application/ld+json">{!! $jsonLd !!}</script>
@endif
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
