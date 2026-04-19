@php
    $tiers    = ($block->content)['tiers'] ?? [];
    $settings = $block->settings ?? [];
    $columns  = (int) ($settings['columns'] ?? 3);
@endphp
@if(count($tiers) > 0)
    <div class="block-pricing-tiers" style="--tier-cols: {{ $columns }};">
@foreach($tiers as $tier)
@php
    $name        = $tier['name'] ?? '';
    $price       = $tier['price'] ?? '';
    $period      = $tier['period'] ?? '';
    $description = $tier['description'] ?? '';
    $features    = $tier['features'] ?? [];
    $ctaText     = $tier['cta_text'] ?? 'Get started';
    $ctaUrl      = $tier['cta_url'] ?? '#';
    $featured    = ! empty($tier['featured']);
@endphp
        <div class="block-pricing-tier{{ $featured ? ' is-featured' : '' }}">
@if($featured)
            <div class="block-pricing-tier-badge">Most popular</div>
@endif
            <h3 class="block-pricing-tier-name">{{ e($name) }}</h3>
@if($price !== '')
            <div class="block-pricing-tier-price">
                <span class="block-pricing-tier-price-num">{{ e($price) }}</span>
@if($period !== '')
                <span class="block-pricing-tier-price-period">{{ e($period) }}</span>
@endif
            </div>
@endif
@if($description !== '')
            <p class="block-pricing-tier-desc">{{ e($description) }}</p>
@endif
@if(count($features) > 0)
            <ul class="block-pricing-tier-features">
@foreach($features as $feature)
                <li>{{ e($feature) }}</li>
@endforeach
            </ul>
@endif
            <a href="{{ e($ctaUrl) }}" class="block-pricing-tier-cta">{{ e($ctaText) }}</a>
        </div>
@endforeach
    </div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-tags',
        'title'   => trans('vela::global.pricing_tiers_empty_title'),
        'message' => trans('vela::global.pricing_tiers_empty_message'),
    ])
@endif
