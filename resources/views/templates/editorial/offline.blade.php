@extends('vela-editorial::layout')

@section('title', __('vela::pwa.offline_title'))

@section('content')
<div class="ed-container" style="display: flex; justify-content: center; align-items: center; min-height: 60vh;">
    <div style="text-align: center; padding: 2rem;">
        <div style="font-size: 4rem; margin-bottom: 1rem;">&#128268;</div>
        <h1>{{ __('vela::pwa.offline_heading') }}</h1>
        <p style="color: #6b7280; margin-bottom: 1.5rem;">{{ __('vela::pwa.offline_message') }}</p>
        <button onclick="window.location.reload()" class="ed-btn ed-btn-primary">{{ __('vela::pwa.try_again') }}</button>
    </div>
</div>
@endsection
