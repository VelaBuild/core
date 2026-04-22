@php
    $settings = $block->settings ?? [];
    $maxCount = $settings['max_count'] ?? 12;
    $columns = $settings['columns'] ?? 3;
    $minRating = $settings['min_rating'] ?? 1;
    $reviews = \VelaBuild\Core\Models\Review::published()
        ->where('rating', '>=', $minRating)
        ->orderBy('review_date', 'desc')
        ->take($maxCount)
        ->get();
@endphp
@if($reviews->isNotEmpty())
<div class="block-review-grid" data-ga-section="reviews" style="display:grid;grid-template-columns:repeat({{ (int)$columns }},1fr);gap:20px;">
@foreach($reviews as $review)
    <div class="review-card" style="border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
        <div style="margin-bottom:10px;">
            <strong>{{ $review->author }}</strong>
            <div>
@for($i = 1; $i <= 5; $i++)
                <i class="fas fa-star {{ $i <= $review->rating ? 'text-warning' : 'text-muted' }}" style="font-size:0.85em;"></i>
@endfor
            </div>
        </div>
@if($review->text)
        <p style="color:#4b5563;">{{ $review->text }}</p>
@endif
@if($review->review_date)
        <small class="text-muted">{{ $review->review_date->format('M j, Y') }}</small>
@endif
    </div>
@endforeach
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-star',
        'title'   => trans('vela::global.reviews_empty_title'),
        'message' => trans('vela::global.reviews_empty_message'),
    ])
@endif
