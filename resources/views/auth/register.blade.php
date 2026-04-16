@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ trans('vela::global.register') }}</p>
@endsection

@section('content')
    <form method="POST" action="{{ route('vela.auth.register.submit') }}">
        @csrf

        <div class="form-group">
            <label for="name">{{ trans('vela::global.user_name') }}</label>
            <input id="name" type="text" name="name" class="form-input{{ $errors->has('name') ? ' is-invalid' : '' }}" required autofocus value="{{ old('name', null) }}">
            @if($errors->has('name'))
                <div class="invalid-feedback">{{ $errors->first('name') }}</div>
            @endif
        </div>

        <div class="form-group">
            <label for="email">{{ trans('vela::global.login_email') }}</label>
            <input id="email" type="email" name="email" class="form-input{{ $errors->has('email') ? ' is-invalid' : '' }}" required value="{{ old('email', null) }}">
            @if($errors->has('email'))
                <div class="invalid-feedback">{{ $errors->first('email') }}</div>
            @endif
        </div>

        <div class="form-group">
            <label for="password">{{ trans('vela::global.login_password') }}</label>
            <input id="password" type="password" name="password" class="form-input{{ $errors->has('password') ? ' is-invalid' : '' }}" required>
            @if($errors->has('password'))
                <div class="invalid-feedback">{{ $errors->first('password') }}</div>
            @endif
        </div>

        <div class="form-group">
            <label for="password_confirmation">{{ trans('vela::global.login_password_confirmation') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" class="form-input" required>
        </div>

        <button type="submit" class="btn btn-primary">{{ trans('vela::global.register') }}</button>
    </form>

    <div class="auth-footer-center">
        <a href="{{ route('vela.auth.login') }}" class="btn-link">{{ trans('vela::global.login') }}</a>
    </div>
@endsection
