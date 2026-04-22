@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head')

<div class="card">
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            {{ __('vela::pwa.app_settings_info') }}
        </div>

        <form action="{{ route('vela.admin.settings.updateGroup', 'app') }}" method="POST">
            @csrf

            <h5>{{ __('vela::pwa.app_store_links') }}</h5>

            <div class="form-group">
                <label for="app_ios_url">{{ __('vela::pwa.app_ios_url') }}</label>
                <input type="url" class="form-control" name="app_ios_url" id="app_ios_url"
                       value="{{ old('app_ios_url', $settings['app_ios_url'] ?? '') }}"
                       placeholder="https://apps.apple.com/app/...">
            </div>

            <div class="form-group">
                <label for="app_android_url">{{ __('vela::pwa.app_android_url') }}</label>
                <input type="url" class="form-control" name="app_android_url" id="app_android_url"
                       value="{{ old('app_android_url', $settings['app_android_url'] ?? '') }}"
                       placeholder="https://play.google.com/store/apps/details?id=...">
            </div>

            <hr>

            <h5>{{ __('vela::pwa.app_configuration') }}</h5>

            <div class="form-group">
                <label for="app_name">{{ __('vela::pwa.app_display_name') }}</label>
                <input type="text" class="form-control" name="app_name" id="app_name"
                       value="{{ old('app_name', $settings['app_name'] ?? '') }}"
                       placeholder="{{ __('vela::pwa.app_name_placeholder') }}">
                <small class="form-text text-muted">{{ __('vela::pwa.app_name_help_text') }}</small>
            </div>

            <div class="form-group">
                <label for="app_custom_scheme">{{ __('vela::pwa.app_custom_scheme') }}</label>
                <input type="text" class="form-control" name="app_custom_scheme" id="app_custom_scheme"
                       value="{{ old('app_custom_scheme', $settings['app_custom_scheme'] ?? '') }}"
                       placeholder="myapp://">
                <small class="form-text text-muted">{{ __('vela::pwa.app_custom_scheme_help') }}</small>
            </div>

            <hr>

            <h5>{{ __('vela::pwa.app_cli_commands') }}</h5>
            <div class="alert alert-secondary">
                <p class="mb-2"><strong>{{ __('vela::pwa.app_init_command') }}:</strong></p>
                <code>php artisan vela:app-init --app-id=com.yourcompany.yourapp</code>
                <p class="mt-3 mb-2"><strong>{{ __('vela::pwa.app_build_command') }}:</strong></p>
                <code>php artisan vela:app-build --platform=android</code>
            </div>

            @can('config_edit')
                <button type="submit" class="btn btn-primary">{{ __('vela::pwa.save') }}</button>
            @endcan
        </form>
    </div>
</div>
@endsection
