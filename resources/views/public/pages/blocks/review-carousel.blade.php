@php
    $settings = $block->settings ?? [];
    $maxCount = $settings['max_count'] ?? 10;
    $minRating = $settings['min_rating'] ?? 1;
    $reviews = \VelaBuild\Core\Models\Review::published()
        ->where('rating', '>=', $minRating)
        ->orderBy('review_date', 'desc')
        ->take($maxCount)
        ->get();
@endphp
@if($reviews->isNotEmpty())
<div class="block-review-carousel" data-ga-section="reviews" style="overflow-x:auto;white-space:nowrap;-webkit-overflow-scrolling:touch;">
    <div style="display:inline-flex;gap:20px;padding:10px 0;">
        @foreach($reviews as $review)
            <div class="review-card" style="min-width:300px;max-width:350px;white-space:normal;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">
                <div class="review-card-header" style="margin-bottom:10px;">
                    <strong>{{ e($review->author) }}</strong>
                    <div>
                        @for($i = 1; $i <= 5; $i++)
                            <i class="fas fa-star {{ $i <= $review->rating ? 'text-warning' : 'text-muted' }}" style="font-size:0.85em;"></i>
                        @endfor
                    </div>
                </div>
                @if($review->text)
                    <p class="review-card-text" style="color:#4b5563;">{{ e($review->text) }}</p>
                @endif
                @if($review->review_date)
                    <small class="text-muted">{{ $review->review_date->format('M j, Y') }}</small>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif
