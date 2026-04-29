@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ trans('vela::global.login_unavailable_title') }}</p>
@endsection

@section('content')
    <div class="alert alert-info" role="alert">
        @if(!empty($disabled))
            {{ trans('vela::global.login_disabled_body') }}
        @else
            {{ trans('vela::global.login_unavailable_body') }}
        @endif
    </div>

    <div class="auth-footer-center">
        <a href="{{ url('/') }}" class="btn-link">{{ trans('vela::global.back_to_site') }}</a>
    </div>
@endsection
