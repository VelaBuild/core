@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ trans('vela::global.reset_password') }}</p>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('vela.auth.password.email') }}">
        @csrf

        <div class="form-group">
            <label for="email">{{ trans('vela::global.login_email') }}</label>
            <input id="email" type="email" name="email" class="form-input{{ $errors->has('email') ? ' is-invalid' : '' }}" required autocomplete="email" autofocus value="{{ old('email') }}">
            @if($errors->has('email'))
                <div class="invalid-feedback">{{ $errors->first('email') }}</div>
            @endif
        </div>

        <button type="submit" class="btn btn-primary">{{ trans('vela::global.send_password') }}</button>
    </form>

    <div class="auth-footer-center">
        <a href="{{ route('vela.auth.login') }}" class="btn-link">{{ trans('vela::global.login') }}</a>
    </div>
@endsection
