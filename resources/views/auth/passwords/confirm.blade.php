@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ __('Confirm Password') }}</p>
@endsection

@section('content')
    <p style="color:#64748b;font-size:14px;margin-bottom:24px">
        {{ __('Please confirm your password before continuing.') }}
    </p>

    <form method="POST" action="{{ route('vela.auth.password.confirm') }}">
        @csrf

        <div class="form-group">
            <label for="password">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" class="form-input @error('password') is-invalid @enderror" required autocomplete="current-password">
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary">{{ __('Confirm Password') }}</button>
    </form>

    @if(Route::has('vela.auth.password.request'))
        <div class="auth-footer-center">
            <a href="{{ route('vela.auth.password.request') }}" class="btn-link">{{ __('Forgot Your Password?') }}</a>
        </div>
    @endif
@endsection
