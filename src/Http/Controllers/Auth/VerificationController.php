<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('vela.auth');
        $this->middleware('throttle:6,1')->only('resend');
    }

    public function show()
    {
        if (auth('vela')->user()->hasVerifiedEmail()) {
            return redirect()->route('vela.admin.home');
        }

        return view('vela::auth.verify');
    }

    public function verify(Request $request)
    {
        $user = auth('vela')->user();

        if (!hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('vela.admin.home');
        }

        $user->markEmailAsVerified();

        return redirect()->route('vela.admin.home')->with('verified', true);
    }

    public function resend(Request $request)
    {
        $user = auth('vela')->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('vela.admin.home');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('resent', true);
    }
}
