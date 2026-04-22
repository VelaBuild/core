@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.config.title') }}
    </div>
    <div class="card-body">
        <div class="row">
            {{-- General Settings --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-globe fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::pwa.settings_general') }}</h5>
                        <p class="text-muted">{{ __('vela::pwa.settings_general_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'general') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Appearance Settings --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-palette fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::pwa.settings_appearance') }}</h5>
                        <p class="text-muted">{{ __('vela::pwa.settings_appearance_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'appearance') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Custom CSS & JS --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-code fa-3x mb-3 text-primary"></i>
                        <h5>{{ trans('vela::global.custom_css_js') }}</h5>
                        <p class="text-muted">{{ trans('vela::global.custom_css_js_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'customcss') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- PWA Settings --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-mobile-alt fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::pwa.settings_pwa') }}</h5>
                        <p class="text-muted">{{ __('vela::pwa.settings_pwa_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'pwa') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- App Settings --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-tablet-alt fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::pwa.settings_app') }}</h5>
                        <p class="text-muted">{{ __('vela::pwa.settings_app_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'app') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Site Visibility --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-eye fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::visibility.settings_title') }}</h5>
                        <p class="text-muted">{{ __('vela::visibility.settings_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'visibility') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- GDPR / Cookie Consent --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-shield-alt fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::gdpr.settings_title') }}</h5>
                        <p class="text-muted">{{ __('vela::gdpr.settings_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'gdpr') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- MCP Server --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-plug fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('vela::mcp.settings_title') }}</h5>
                        <p class="text-muted">{{ __('vela::mcp.settings_desc') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.group', 'mcp') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Tracking & conversion --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-bullseye fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('Tracking') }}</h5>
                        <p class="text-muted">{{ __('GA4, GTM, Meta Pixel + Conversions API, Google Ads.') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.tracking.index') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Design System --}}
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <i class="fas fa-swatchbook fa-3x mb-3 text-primary"></i>
                        <h5>{{ __('Design System') }}</h5>
                        <p class="text-muted">{{ __('Files, colour palette, fonts — used by the block editor and the AI.') }}</p>
                        @can('config_access')
                            <a href="{{ route('vela.admin.settings.design-system.index') }}" class="btn btn-primary btn-sm">
                                {{ __('vela::pwa.manage') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>

            {{-- Plugin-contributed cards.
                 Any plugin that wants to expose its settings on this index
                 should provide a `<namespace>::admin.partials.settings-card`
                 view. Core includes each when present — no direct coupling
                 to specific plugins. Add new plugins to this list as they
                 grow; a proper registry is future work. --}}
            @foreach(['vela-store', 'vela-marketplace'] as $__pluginNs)
                @if(view()->exists($__pluginNs . '::admin.partials.settings-card'))
                    @include($__pluginNs . '::admin.partials.settings-card')
                @endif
            @endforeach
        </div>
    </div>
</div>
@endsection
