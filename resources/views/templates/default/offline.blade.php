@extends('vela::templates.default.layout')

@section('title', __('vela::pwa.offline_title'))

@section('content')
<div style="display: flex; justify-content: center; align-items: center; min-height: 60vh;">
    <div style="text-align: center; padding: 2rem;">
        <div style="font-size: 4rem; margin-bottom: 1rem;">&#128268;</div>
        <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">{{ __('vela::pwa.offline_heading') }}</h1>
        <p style="color: #6b7280; margin-bottom: 1.5rem;">{{ __('vela::pwa.offline_message') }}</p>
        <button onclick="window.location.reload()" style="background: #1f2937; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; cursor: pointer; font-size: 1rem;">{{ __('vela::pwa.try_again') }}</button>
    </div>
</div>
@endsection
