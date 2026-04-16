<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\VelaUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function showRegistrationForm()
    {
        return view('vela::auth.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:vela_users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = VelaUser::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $defaultRole = config('vela.registration_default_role', '2');
        if ($defaultRole) {
            $user->roles()->attach($defaultRole);
        }

        auth('vela')->login($user);

        return redirect()->route('vela.admin.home');
    }
}
