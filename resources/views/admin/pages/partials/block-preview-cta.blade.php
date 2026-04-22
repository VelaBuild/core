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
@if($heading || $description || $primaryText || $secondaryText)
    <div style="background:#f0f4ff; border-radius:4px; padding:16px; text-align:{{ $alignment }};">
        @if($heading)
            <h5 style="margin:0 0 4px;">{{ $heading }}</h5>
        @endif
        @if($description)
            <p style="margin:0 0 8px; color:#6c757d;">{{ $description }}</p>
        @endif
        @if($primaryText || $secondaryText)
            <div>
                @if($primaryText)
                    <span class="badge badge-primary">{{ $primaryText }}</span>
                    @if($primaryUrl) <small class="text-muted ml-1">{{ $primaryUrl }}</small> @endif
                @endif
                @if($secondaryText)
                    <span class="badge badge-secondary ml-1">{{ $secondaryText }}</span>
                @endif
            </div>
        @endif
    </div>
@else
    <em class="text-muted">{{ trans('vela::global.empty_block') }}</em>
@endif
