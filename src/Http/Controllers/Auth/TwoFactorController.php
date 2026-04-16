<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Requests\CheckTwoFactorRequest;
use VelaBuild\Core\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwoFactorController extends Controller
{
    public function show()
    {
        abort_if(auth('vela')->user()->two_factor_code === null,
            Response::HTTP_FORBIDDEN,
            '403 Forbidden'
        );

        return view('vela::auth.twoFactor');
    }

    public function check(CheckTwoFactorRequest $request)
    {
        $user = auth('vela')->user();

        if ($request->input('two_factor_code') == $user->two_factor_code) {
            $user->resetTwoFactorCode();

            return redirect()->route('vela.admin.home');
        }

        return redirect()->back()->withErrors(['two_factor_code' => __('vela::global.two_factor.does_not_match')]);
    }

    public function resend()
    {
        abort_if(auth('vela')->user()->two_factor_code === null,
            Response::HTTP_FORBIDDEN,
            '403 Forbidden'
        );

        auth('vela')->user()->notify(new TwoFactorCodeNotification());

        return redirect()->back()->with('message', __('vela::global.two_factor.sent_again'));
    }
}
