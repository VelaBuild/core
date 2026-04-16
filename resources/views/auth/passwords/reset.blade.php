@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ trans('vela::global.reset_password') }}</p>
@endsection

@section('content')
    <form method="POST" action="{{ route('vela.auth.password.update') }}">
        @csrf
        <input name="token" value="{{ $token }}" type="hidden">

        <div class="form-group">
            <label for="email">{{ trans('vela::global.login_email') }}</label>
            <input id="email" type="email" name="email" class="form-input{{ $errors->has('email') ? ' is-invalid' : '' }}" required autocomplete="email" autofocus value="{{ $email ?? old('email') }}">
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
            <label for="password-confirm">{{ trans('vela::global.login_password_confirmation') }}</label>
            <input id="password-confirm" type="password" name="password_confirmation" class="form-input" required>
        </div>

        <button type="submit" class="btn btn-primary">{{ trans('vela::global.reset_password') }}</button>
    </form>
@endsection
