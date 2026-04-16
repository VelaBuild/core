@php
    $currentGroup = request()->route('group') ?? '';
    $settingsGroups = [
        'general'    => ['label' => __('vela::pwa.settings_general'),    'icon' => 'fas fa-globe'],
        'appearance' => ['label' => __('vela::pwa.settings_appearance'), 'icon' => 'fas fa-palette'],
        'customcss'  => ['label' => __('vela::global.custom_css_js'),    'icon' => 'fas fa-code'],
        'pwa'        => ['label' => __('vela::pwa.settings_pwa'),        'icon' => 'fas fa-mobile-alt'],
        'app'        => ['label' => __('vela::pwa.settings_app'),        'icon' => 'fas fa-tablet-alt'],
        'visibility' => ['label' => __('vela::visibility.settings_title'), 'icon' => 'fas fa-eye'],
        'gdpr'       => ['label' => __('vela::gdpr.settings_title'),     'icon' => 'fas fa-shield-alt'],
    ];
    $current = $settingsGroups[$currentGroup] ?? null;
@endphp
<div class="dropdown d-inline-block">
    <button class="btn btn-link text-dark font-weight-bold p-0 dropdown-toggle" type="button" id="settingsNavDropdown" data-toggle="dropdown" data-coreui-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="font-size:1.25rem; text-decoration:none;">
        <i class="{{ $current['icon'] ?? 'fas fa-cog' }} mr-1"></i> {{ $current['label'] ?? trans('vela::cruds.config.title') }}
    </button>
    <div class="dropdown-menu" aria-labelledby="settingsNavDropdown">
        <a class="dropdown-item" href="{{ route('vela.admin.settings.index') }}">
            <i class="fas fa-th-large mr-2"></i> {{ trans('vela::cruds.config.title') }}
        </a>
        <div class="dropdown-divider"></div>
        @foreach($settingsGroups as $group => $meta)
            @can('config_access')
                <a class="dropdown-item {{ $currentGroup === $group ? 'active' : '' }}"
                   href="{{ route('vela.admin.settings.group', $group) }}">
                    <i class="{{ $meta['icon'] }} mr-2"></i> {{ $meta['label'] }}
                </a>
            @endcan
        @endforeach
    </div>
</div>
