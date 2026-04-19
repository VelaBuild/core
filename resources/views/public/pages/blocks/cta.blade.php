@php
    $content = $block->content ?? [];
    $settings = $block->settings ?? [];
    $heading = $content['heading'] ?? '';
    $description = $content['description'] ?? '';
    $primaryText = $content['primary_button_text'] ?? '';
    $primaryUrl = $content['primary_button_url'] ?? '';
    $secondaryText = $content['secondary_button_text'] ?? '';
    $secondaryUrl = $content['secondary_button_url'] ?? '';
    $alignment = $settings['text_alignment'] ?? 'center';
@endphp
<div class="block-cta" style="text-align:{{ e($alignment) }};">
    <div class="block-cta-inner">
@if($heading)
        <h2 class="block-cta-heading">{{ e($heading) }}</h2>
@endif
@if($description)
        <p class="block-cta-description">{{ e($description) }}</p>
@endif
@if($primaryText || $secondaryText)
        <div class="block-cta-actions" style="justify-content:{{ e($alignment) }};">
@if($primaryText)
            <a href="{{ e($primaryUrl) }}" class="block-cta-btn block-cta-btn-primary">{{ e($primaryText) }}</a>
@endif
@if($secondaryText)
            <a href="{{ e($secondaryUrl) }}" class="block-cta-btn block-cta-btn-secondary">{{ e($secondaryText) }}</a>
@endif
        </div>
@endif
    </div>
</div>
