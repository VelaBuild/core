{{-- Settings dropdown — entries come from SettingsNavRegistry. Plugins
     and core extend via Vela::registerSettingsItem(). DO NOT add hardcoded
     entries here, and DO NOT publish this file to the host app — see
     resources/views/admin/settings/README.md (or run `php artisan vela:checks`). --}}
@php
    $registry = app(\VelaBuild\Core\Vela::class)->settingsNav();
    $items    = $registry->all();
    $current  = $registry->current();
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
        @foreach($items as $item)
            @php $url = $registry->url($item); @endphp
            @if($url)
                <a class="dropdown-item {{ ($current['key'] ?? null) === $item['key'] ? 'active' : '' }}" href="{{ $url }}">
                    <i class="{{ $item['icon'] }} mr-2"></i> {{ $item['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</div>
