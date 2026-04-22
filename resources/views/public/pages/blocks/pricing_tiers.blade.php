@php
    $tiers    = ($block->content)['tiers'] ?? [];
    $settings = $block->settings ?? [];
    $columns  = (int) ($settings['columns'] ?? 3);
@endphp
@if(count($tiers) > 0)
    <div class="block-pricing-tiers" style="--tier-cols: {{ $columns }};">
@foreach($tiers as $tier)
@php
    $name         = $tier['name'] ?? '';
    $subtitle     = $tier['subtitle'] ?? '';
    $price        = $tier['price'] ?? '';
    $priceCurrency = $tier['price_currency'] ?? '$';
    $period       = $tier['period'] ?? '';
    $priceNote    = $tier['price_note'] ?? '';
    $description  = $tier['description'] ?? '';
    $featuresCap  = $tier['features_cap'] ?? '';
    $features     = $tier['features'] ?? [];
    $ctaText      = $tier['cta_text'] ?? 'Get started';
    $ctaUrl       = $tier['cta_url'] ?? '#';
    $featured     = ! empty($tier['featured']);
    $badge        = $tier['badge'] ?? 'Most popular';
@endphp
        <div class="block-pricing-tier{{ $featured ? ' is-featured' : '' }}">
@if($featured)
            <span class="block-pricing-tier-badge">{{ $badge }}</span>
@endif
@if($name !== '')
            <div class="block-pricing-tier-label">{{ $name }}</div>
@endif
@if($subtitle !== '')
            <h3 class="block-pricing-tier-headline">{{ $subtitle }}</h3>
@endif
@if($description !== '')
            <p class="block-pricing-tier-desc">{{ $description }}</p>
@endif
@if($price !== '')
            <div class="block-pricing-tier-price">
                <span class="block-pricing-tier-price-cur">{{ $priceCurrency }}</span>
                <span class="block-pricing-tier-price-num">{{ $price }}</span>
@if($period !== '')
                <span class="block-pricing-tier-price-period">{{ $period }}</span>
@endif
            </div>
@endif
@if($priceNote !== '')
            <div class="block-pricing-tier-price-note">{{ $priceNote }}</div>
@endif
            <a href="{{ $ctaUrl }}" class="block-pricing-tier-cta">{{ $ctaText }}</a>
@if($featuresCap !== '')
            <div class="block-pricing-tier-features-cap">{{ $featuresCap }}</div>
@endif
@if(count($features) > 0)
            <ul class="block-pricing-tier-features">
@foreach($features as $feature)
@php
    $text  = is_array($feature) ? ($feature['text'] ?? '') : $feature;
    $muted = is_array($feature) ? !empty($feature['muted']) : false;
@endphp
                <li class="{{ $muted ? 'is-muted' : '' }}">{{ $text }}</li>
@endforeach
            </ul>
@endif
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
