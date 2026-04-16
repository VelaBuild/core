@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.settings._nav')
    </div>
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-warning">{{ session('error') }}</div>
        @endif

        <form action="{{ route('vela.admin.settings.updateGroup', 'gdpr') }}" method="POST">
            @csrf

            {{-- Enable/Disable --}}
            <div class="form-group">
                <div class="custom-control custom-switch">
                    <input type="hidden" name="gdpr_enabled" value="0">
                    <input type="checkbox" class="custom-control-input" id="gdpr_enabled" name="gdpr_enabled" value="1"
                        {{ $effectiveEnabled ? 'checked' : '' }}>
                    <label class="custom-control-label" for="gdpr_enabled">
                        {{ __('vela::gdpr.enable_label') }}
                    </label>
                </div>
                <small class="form-text text-muted">
                    {{ __('vela::gdpr.enable_help') }}
                </small>
                @if($envGdpr !== null && !isset($settings['gdpr_enabled']))
                    <small class="form-text text-info">
                        <i class="fas fa-info-circle"></i>
                        {{ __('vela::gdpr.env_active', ['var' => 'VELA_GDPR', 'value' => $envGdpr ? 'true' : 'false']) }}
                    </small>
                @endif
            </div>

            {{-- Privacy Policy URL --}}
            <div class="form-group">
                <label for="gdpr_privacy_url">{{ __('vela::gdpr.privacy_url_label') }}</label>
                <input type="text" class="form-control" name="gdpr_privacy_url" id="gdpr_privacy_url"
                    value="{{ old('gdpr_privacy_url', $effectivePrivacyUrl) }}"
                    placeholder="/privacy">
                <small class="form-text text-muted">
                    {{ __('vela::gdpr.privacy_url_help') }}
                </small>
                @if($envPrivacyUrl && !isset($settings['gdpr_privacy_url']))
                    <small class="form-text text-info">
                        <i class="fas fa-info-circle"></i>
                        {{ __('vela::gdpr.env_active', ['var' => 'VELA_PRIVACY_URL', 'value' => $envPrivacyUrl]) }}
                    </small>
                @endif
            </div>

            @can('config_edit')
                <button type="submit" class="btn btn-primary">{{ __('vela::pwa.save') }}</button>
            @endcan
        </form>

        <hr class="my-4">

        {{-- Privacy Page Status --}}
        <h5>{{ __('vela::gdpr.privacy_page_section') }}</h5>

        @if($privacyPageExists)
            <div class="alert alert-success mb-3">
                <i class="fas fa-check-circle"></i>
                {{ __('vela::gdpr.privacy_page_found', ['slug' => $privacySlug]) }}
                <a href="{{ url($privacySlug) }}" target="_blank" class="alert-link ml-2">
                    {{ __('vela::gdpr.view_page') }} <i class="fas fa-external-link-alt fa-sm"></i>
                </a>
            </div>
        @else
            <div class="alert alert-warning mb-3">
                <i class="fas fa-exclamation-triangle"></i>
                {{ __('vela::gdpr.privacy_page_missing', ['slug' => $privacySlug]) }}
            </div>

            @can('config_edit')
                <form action="{{ route('vela.admin.settings.gdpr.installPrivacyPage') }}" method="POST" class="d-inline">
                    @csrf
                    <input type="hidden" name="slug" value="{{ $privacySlug }}">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="fas fa-plus-circle"></i>
                        {{ __('vela::gdpr.install_privacy_page') }}
                    </button>
                    <small class="form-text text-muted mt-1">
                        {{ __('vela::gdpr.install_privacy_page_help') }}
                    </small>
                </form>
            @endcan
        @endif

        <hr class="my-4">

        {{-- Info --}}
        <h5>{{ __('vela::gdpr.what_this_does') }}</h5>
        <ul class="text-muted" style="line-height:2">
            <li>{{ __('vela::gdpr.info_banner') }}</li>
            <li>{{ __('vela::gdpr.info_analytics') }}</li>
            <li>{{ __('vela::gdpr.info_consent_cookie') }}</li>
            <li>{{ __('vela::gdpr.info_categories') }}</li>
        </ul>
    </div>
</div>
@endsection
