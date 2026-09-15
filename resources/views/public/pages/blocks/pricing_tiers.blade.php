@php
    $tiers    = ($block->content)['tiers'] ?? [];
    $settings = $block->settings ?? [];
    // Never more columns than plans: four columns for three plans left an
    // empty fourth, and the cards sat to one side of the row.
    $columns  = max(1, min(4, (int) ($settings['columns'] ?? 3), count($tiers)));

    // clean is the card the theme draws, and what every block saved before
    // there was a choice gets. soft and outline name the block twice in
    // page-blocks.css so they outrank a theme's own card rule.
    $style = in_array($settings['card_style'] ?? '', ['clean', 'soft', 'outline'], true) ? $settings['card_style'] : 'clean';

    // Three colours an owner may set; each left empty follows the theme. A
    // colour is stored as `token:name` or a literal, and the ink on it is
    // chosen from it, so no choice can make a card or button unreadable.
    $vars = ['--tier-cols: ' . $columns . ';'];
    $classes = ['block-pricing-tiers', 'block-pricing-tiers--' . $style];
    foreach (['card_color' => 'card', 'button_color' => 'button', 'featured_color' => 'featured'] as $key => $part) {
        $bg = \VelaBuild\Core\Services\DesignTokens::colour($settings[$key] ?? null);
        if ($bg === null) {
            continue;
        }
        $vars[] = '--tier-' . $part . '-bg: ' . $bg . ';';
        if ($ink = \VelaBuild\Core\Services\DesignTokens::inkFor($settings[$key])) {
            $vars[] = '--tier-' . $part . '-ink: ' . $ink . ';';
        }
        $classes[] = 'has-' . $part . '-color';
    }
@endphp
@if(count($tiers) > 0)
    <div class="{{ implode(' ', $classes) }}" style="{{ implode(' ', $vars) }}">
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
    // `?:`, not `??`: the block editor saves a box left empty as '', and an
    // empty string is not null — the button came out with no words, the
    // highlighted card with an empty badge.
    $ctaText      = ($tier['cta_text'] ?? '') ?: 'Get started';
    $ctaUrl       = ($tier['cta_url'] ?? '') ?: '#';
    $featured     = ! empty($tier['featured']);
    $badge        = ($tier['badge'] ?? '') ?: 'Most popular';
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
