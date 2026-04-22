@php
    $content       = $block->content ?? [];
    $settings      = $block->settings ?? [];
    $title         = $content['title'] ?? '';
    $subtitle      = $content['subtitle'] ?? '';
    $primaryText   = $content['primary_button_text'] ?? '';
    $primaryUrl    = $content['primary_button_url'] ?? '';
    $secondaryText = $content['secondary_button_text'] ?? '';
    $secondaryUrl  = $content['secondary_button_url'] ?? '';
    $overlay       = $settings['background_overlay'] ?? 'rgba(0,0,0,0.4)';
    $alignment     = $settings['text_alignment'] ?? 'center';
    $minHeight     = $settings['min_height'] ?? '80vh';
@endphp
<div class="block-hero" style="min-height:{{ $minHeight }};text-align:{{ $alignment }};">
    <div class="block-hero-overlay" style="background:{{ $overlay }};"></div>
    <div class="block-hero-inner">
@if($title)
        <h1 class="block-hero-title">{{ $title }}</h1>
@endif
@if($subtitle)
        <p class="block-hero-subtitle">{{ $subtitle }}</p>
@endif
@if($primaryText || $secondaryText)
        <div class="block-hero-actions" style="justify-content:{{ $alignment }};">
@if($primaryText)
            <a href="{{ $primaryUrl }}" class="block-hero-btn block-hero-btn-primary">{{ $primaryText }}</a>
@endif
@if($secondaryText)
            <a href="{{ $secondaryUrl }}" class="block-hero-btn block-hero-btn-secondary">{{ $secondaryText }}</a>
@endif
        </div>
@endif
    </div>
</div>
