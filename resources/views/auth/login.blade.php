@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ trans('vela::global.login') }}</p>
@endsection

@section('content')
    @if(session('message'))
        <div class="alert alert-info">
            {{ session('message') }}
        </div>
    @endif

    <form method="POST" action="{{ route('vela.auth.login.submit') }}">
        @csrf

        <div class="form-group">
            <label for="email">{{ trans('vela::global.login_email') }}</label>
            <input id="email" name="email" type="text" class="form-input{{ $errors->has('email') ? ' is-invalid' : '' }}" required autocomplete="email" autofocus value="{{ old('email', null) }}">
            @if($errors->has('email'))
                <div class="invalid-feedback">{{ $errors->first('email') }}</div>
            @endif
        </div>

        <div class="form-group">
            <label for="password">{{ trans('vela::global.login_password') }}</label>
            <input id="password" name="password" type="password" class="form-input{{ $errors->has('password') ? ' is-invalid' : '' }}" required>
            @if($errors->has('password'))
                <div class="invalid-feedback">{{ $errors->first('password') }}</div>
            @endif
        </div>

        <div class="form-group">
            <div class="form-check">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">{{ trans('vela::global.remember_me') }}</label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{{ trans('vela::global.login') }}</button>
    </form>

    <div class="auth-footer">
        @if(Route::has('vela.auth.password.request'))
            <a href="{{ route('vela.auth.password.request') }}" class="btn-link">{{ trans('vela::global.forgot_password') }}</a>
        @endif
        <a href="{{ route('vela.auth.register') }}" class="btn-link">{{ trans('vela::global.register') }}</a>
    </div>
@endsection
