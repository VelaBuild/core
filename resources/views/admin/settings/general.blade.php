@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head')

<div class="card">
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-warning">{{ session('error') }}</div>
        @endif
        <form action="{{ route('vela.admin.settings.updateGroup', 'general') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="site_name">{{ __('vela::pwa.site_name') }}</label>
                <input type="text" class="form-control" name="site_name" id="site_name" value="{{ old('site_name', $settings['site_name'] ?? '') }}">
            </div>
            <div class="form-group">
                <label for="site_niche">{{ __('vela::pwa.site_niche') }}</label>
                <input type="text" class="form-control" name="site_niche" id="site_niche" value="{{ old('site_niche', $settings['site_niche'] ?? '') }}">
            </div>
            <div class="form-group">
                <label for="site_tagline">{{ trans('vela::global.tagline') }}</label>
                <input type="text" class="form-control" name="site_tagline" id="site_tagline" value="{{ old('site_tagline', $settings['site_tagline'] ?? '') }}" placeholder="{{ trans('vela::global.tagline_placeholder') }}">
                <small class="form-text text-muted">{{ trans('vela::global.tagline_help') }}</small>
            </div>
            <div class="form-group">
                <label for="site_description">{{ __('vela::pwa.site_description') }}</label>
                <textarea class="form-control" name="site_description" id="site_description" rows="3">{{ old('site_description', $settings['site_description'] ?? '') }}</textarea>
            </div>
            @can('config_edit')
                <button type="submit" class="btn btn-primary">{{ __('vela::pwa.save') }}</button>
            @endcan
        </form>
    </div>
</div>
@endsection
