{{-- Shared empty-state for page-builder blocks.
     Params:
       $icon     — FontAwesome class (optional, e.g. "fa-newspaper")
       $title    — short heading (required)
       $message  — subtext shown to all visitors
       $ctaText  — optional admin-only CTA button text
       $ctaUrl   — optional admin-only CTA URL
--}}
@php
    $icon    = $icon    ?? null;
    $title   = $title   ?? '';
    $message = $message ?? '';
    $ctaText = $ctaText ?? null;
    $ctaUrl  = $ctaUrl  ?? null;
    $isAdmin = auth('vela')->check();
@endphp
<div class="block-empty-state">
@if($icon)
    <div class="block-empty-state-icon"><i class="fas {{ $icon }}"></i></div>
@endif
@if($title)
    <div class="block-empty-state-title">{{ $title }}</div>
@endif
@if($message)
    <p class="block-empty-state-message">{{ $message }}</p>
@endif
@if($isAdmin && $ctaText && $ctaUrl)
    <a href="{{ $ctaUrl }}" class="block-empty-state-cta">{{ $ctaText }}</a>
@endif
</div>
