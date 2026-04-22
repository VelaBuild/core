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

        <form action="{{ route('vela.admin.settings.updateGroup', 'pwa') }}" method="POST" enctype="multipart/form-data">
            @csrf

            {{-- Master toggle --}}
            <div class="form-group">
                <div class="custom-control custom-switch">
                    <input type="hidden" name="pwa_enabled" value="0">
                    <input type="checkbox" class="custom-control-input" id="pwa_enabled" name="pwa_enabled" value="1" {{ ($settings['pwa_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="pwa_enabled">{{ __('vela::pwa.enable_pwa') }}</label>
                </div>
            </div>

            <hr>

            {{-- Manifest settings --}}
            <h5>{{ __('vela::pwa.manifest_settings') }}</h5>
            <div class="form-group">
                <label for="pwa_name">{{ __('vela::pwa.app_name') }}</label>
                <input type="text" class="form-control" name="pwa_name" id="pwa_name" value="{{ old('pwa_name', $settings['pwa_name'] ?? '') }}" placeholder="{{ config('app.name') }}">
                <small class="form-text text-muted">{{ __('vela::pwa.app_name_help') }}</small>
            </div>
            <div class="form-group">
                <label for="pwa_short_name">{{ __('vela::pwa.short_name') }}</label>
                <input type="text" class="form-control" name="pwa_short_name" id="pwa_short_name" value="{{ old('pwa_short_name', $settings['pwa_short_name'] ?? '') }}" maxlength="12">
                <small class="form-text text-muted">{{ __('vela::pwa.short_name_help') }}</small>
            </div>
            <div class="form-group">
                <label for="pwa_description">{{ __('vela::pwa.pwa_description') }}</label>
                <textarea class="form-control" name="pwa_description" id="pwa_description" rows="2">{{ old('pwa_description', $settings['pwa_description'] ?? '') }}</textarea>
            </div>
            <div class="form-group">
                <label for="pwa_display">{{ __('vela::pwa.display_mode') }}</label>
                <select class="form-control" name="pwa_display" id="pwa_display">
                    @foreach(['standalone', 'fullscreen', 'minimal-ui', 'browser'] as $mode)
                        <option value="{{ $mode }}" {{ ($settings['pwa_display'] ?? 'standalone') === $mode ? 'selected' : '' }}>{{ ucfirst($mode) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pwa_theme_color">{{ __('vela::pwa.theme_color') }}</label>
                        <input type="color" class="form-control" name="pwa_theme_color" id="pwa_theme_color" value="{{ $settings['pwa_theme_color'] ?? '#1f2937' }}">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="pwa_background_color">{{ __('vela::pwa.background_color') }}</label>
                        <input type="color" class="form-control" name="pwa_background_color" id="pwa_background_color" value="{{ $settings['pwa_background_color'] ?? '#ffffff' }}">
                    </div>
                </div>
            </div>

            <hr>

            {{-- Icon upload --}}
            <h5>{{ __('vela::pwa.icon_settings') }}</h5>
            @if(!empty($settings['pwa_icon_source']))
                <div class="mb-3">
                    <img src="{{ asset('storage/pwa-icons/icon-192x192.png') }}" alt="Current PWA icon" style="width:96px;height:96px;border:1px solid #dee2e6;border-radius:8px;">
                    <p class="text-muted mt-1">{{ __('vela::pwa.current_icon') }}</p>
                </div>
            @endif
            <div class="form-group">
                <label for="pwa_icon">{{ __('vela::pwa.upload_icon') }}</label>
                <input type="file" class="form-control-file" name="pwa_icon" id="pwa_icon" accept="image/png,image/jpeg,image/webp">
                <small class="form-text text-muted">{{ __('vela::pwa.icon_requirements') }}</small>
            </div>

            <hr>

            {{-- Offline/Cache settings --}}
            <h5>{{ __('vela::pwa.offline_settings') }}</h5>
            <div class="form-group">
                <div class="custom-control custom-switch">
                    <input type="hidden" name="pwa_offline_enabled" value="0">
                    <input type="checkbox" class="custom-control-input" id="pwa_offline_enabled" name="pwa_offline_enabled" value="1" {{ ($settings['pwa_offline_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="pwa_offline_enabled">{{ __('vela::pwa.enable_offline') }}</label>
                </div>
            </div>
            <div class="form-group">
                <label for="pwa_precache_urls">{{ __('vela::pwa.precache_urls') }}</label>
                <textarea class="form-control" name="pwa_precache_urls" id="pwa_precache_urls" rows="3" placeholder="/,/posts,/about">{{ old('pwa_precache_urls', $settings['pwa_precache_urls'] ?? '') }}</textarea>
                <small class="form-text text-muted">{{ __('vela::pwa.precache_urls_help') }}</small>
            </div>

            @can('config_edit')
                <button type="submit" class="btn btn-primary">{{ __('vela::pwa.save') }}</button>
            @endcan
        </form>
    </div>
</div>
@endsection
