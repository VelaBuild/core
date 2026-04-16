@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ __('vela::global.two_factor.title') }}</p>
@endsection

@section('content')
    @if(session()->has('message'))
        <div class="alert alert-info">
            {{ session()->get('message') }}
        </div>
    @endif

    <p style="color:#64748b;font-size:14px;margin-bottom:24px">
        {{ __('vela::global.two_factor.sub_title', ['minutes' => 15]) }}
    </p>

    <form method="POST" action="{{ route('vela.auth.two-factor.check') }}">
        @csrf

        <div class="form-group">
            <label for="two_factor_code">{{ trans('vela::global.two_factor.code') }}</label>
            <input id="two_factor_code" name="two_factor_code" type="text" class="form-input{{ $errors->has('two_factor_code') ? ' is-invalid' : '' }}" required autofocus autocomplete="one-time-code" inputmode="numeric">
            @if($errors->has('two_factor_code'))
                <div class="invalid-feedback">{{ $errors->first('two_factor_code') }}</div>
            @endif
        </div>

        <button type="submit" class="btn btn-primary">{{ trans('vela::global.two_factor.verify') }}</button>
    </form>

    <div class="auth-footer">
        <a href="{{ route('vela.auth.two-factor.resend') }}" class="btn-link">{{ __('vela::global.two_factor.resend') }}</a>
        <form id="logoutform" action="{{ route('vela.auth.logout') }}" method="POST" style="display:inline">
            @csrf
            <button type="submit" class="btn-link" style="color:#dc2626">{{ trans('vela::global.logout') }}</button>
        </form>
    </div>
@endsection
