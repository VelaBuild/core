{{-- Shared page-head for every Settings sub-page.
     Entries come from SettingsNavRegistry — extend via
     Vela::registerSettingsItem(), never by editing this file.

     Renders:
       • Breadcrumb   → "Settings / {current label}"
       • Title row    → _nav dropdown + optional subtitle
       • Back button  → to the main Settings index --}}
@php
    $current = app(\VelaBuild\Core\Vela::class)->settingsNav()->current();
    $__currentLabel = $current['label'] ?? trans('vela::cruds.config.title');
@endphp
<div class="vela-page-head">
    <div class="vela-page-head-left">
        <div class="vela-breadcrumb">
            <a href="{{ route('vela.admin.settings.index') }}">{{ __('Settings') }}</a>
            / <span class="cur">{{ $__currentLabel }}</span>
        </div>
        <div class="vela-page-title-row">
            <div>
                @include('vela::admin.settings._nav')
                @isset($subtitle)
                    <p class="vela-page-sub">{{ $subtitle }}</p>
                @endisset
            </div>
        </div>
    </div>
    <div class="vela-page-actions">
        <a class="btn btn-secondary" href="{{ route('vela.admin.settings.index') }}">{{ __('Back') }}</a>
    </div>
</div>
