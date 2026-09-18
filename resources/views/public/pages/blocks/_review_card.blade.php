{{-- One review, shared by the grid and the carousel.
     Params: $review, $s (list settings) --}}
@php
    $stars = \VelaBuild\Core\Services\Blocks\Reviews::stars(
        (float) $review->rating,
        trans('vela::global.review_rating_label', ['rating' => $review->rating])
    );
@endphp
<article class="review-card">
    <div class="review-card-head">
        <span class="review-card-author">{{ $review->author }}</span>
        {!! $stars !!}
    </div>
@if($review->text)
    <p class="review-card-text">{{ $review->text }}</p>
@endif
@if(($s['show_date'] && $review->review_date) || ($s['show_source'] && $review->source))
    <div class="review-card-meta">
@if($s['show_date'] && $review->review_date)
        <time datetime="{{ $review->review_date->toDateString() }}">{{ $review->review_date->translatedFormat('j M Y') }}</time>
@endif
@if($s['show_source'] && $review->source)
        <span class="review-card-source">{{ ucfirst($review->source) }}</span>
@endif
    </div>
@endif
</article>
