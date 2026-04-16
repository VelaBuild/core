<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ConfirmPasswordController extends Controller
{
    public function __construct()
    {
        $this->middleware('vela.auth');
    }

    public function showConfirmForm()
    {
        return view('vela::auth.passwords.confirm');
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'password' => 'required',
        ]);

        if (!auth('vela')->validate([
            'email' => auth('vela')->user()->email,
            'password' => $request->password,
        ])) {
            return back()->withErrors([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('vela.admin.home'));
    }
}
