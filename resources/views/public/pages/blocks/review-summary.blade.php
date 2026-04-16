@php
    $settings = $block->settings ?? [];
    $minRating = $settings['min_rating'] ?? 1;
    $reviews = \VelaBuild\Core\Models\Review::published()
        ->where('rating', '>=', $minRating)
        ->get();
    $avgRating = $reviews->avg('rating');
    $count = $reviews->count();
@endphp
@if($count > 0)
<div class="block-review-summary" data-ga-section="reviews">
    <div class="review-summary-stars">
        @for($i = 1; $i <= 5; $i++)
            <i class="fas fa-star {{ $i <= round($avgRating) ? 'text-warning' : 'text-muted' }}"></i>
        @endfor
    </div>
    <div class="review-summary-text">
        <strong>{{ number_format($avgRating, 1) }}</strong> out of 5 based on <strong>{{ $count }}</strong> {{ Str::plural('review', $count) }}
    </div>
</div>
@endif
