@extends('vela::layouts.auth')

@section('subtitle')
    <p>{{ __('Verify Your Email Address') }}</p>
@endsection

@section('content')
    @if(session('resent'))
        <div class="alert alert-success">
            {{ __('A fresh verification link has been sent to your email address.') }}
        </div>
    @endif

    <p style="color:#475569;font-size:14px;margin-bottom:20px">
        {{ __('Before proceeding, please check your email for a verification link.') }}
    </p>

    <p style="color:#475569;font-size:14px">
        {{ __('If you did not receive the email') }},
        <form class="d-inline" method="POST" action="{{ route('vela.auth.verification.resend') }}" style="display:inline">
            @csrf
            <button type="submit" class="btn-link">{{ __('click here to request another') }}</button>.
        </form>
    </p>
@endsection
