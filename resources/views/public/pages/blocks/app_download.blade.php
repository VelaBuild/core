@php
    $content     = $block->content ?? [];
    $settings    = $block->settings ?? [];
    $heading     = $content['heading'] ?? '';
    $description = $content['description'] ?? '';
    $alignment   = $settings['text_alignment'] ?? 'center';

    $iosUrl     = vela_config('app_ios_url');
    $androidUrl = vela_config('app_android_url');
@endphp
@if($iosUrl || $androidUrl)
<div class="block-app-download" style="text-align:{{ e($alignment) }};">
    <div class="block-app-download-inner">
@if($heading)
        <h2 class="block-app-download-heading">{{ e($heading) }}</h2>
@endif
@if($description)
        <p class="block-app-download-description">{{ e($description) }}</p>
@endif
        <div class="block-app-download-badges" style="display:flex;gap:12px;justify-content:{{ e($alignment) }};flex-wrap:wrap;">
@if($iosUrl)
            <a href="{{ e($iosUrl) }}" target="_blank" rel="noopener noreferrer" class="block-app-download-badge" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#000;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;">
                <i class="fab fa-apple" style="font-size:24px;"></i>
                <span><small style="display:block;font-size:11px;">Download on the</small>App Store</span>
            </a>
@endif
@if($androidUrl)
            <a href="{{ e($androidUrl) }}" target="_blank" rel="noopener noreferrer" class="block-app-download-badge" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#000;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;">
                <i class="fab fa-google-play" style="font-size:24px;"></i>
                <span><small style="display:block;font-size:11px;">Get it on</small>Google Play</span>
            </a>
@endif
        </div>
    </div>
</div>
@endif
