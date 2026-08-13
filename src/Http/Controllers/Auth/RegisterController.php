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
        $this->abortIfDisabled();

        return view('vela::auth.register');
    }

    public function register(Request $request)
    {
        $this->abortIfDisabled();

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

        // The default role is attached by the VelaUser "created" hook, so there is
        // nothing to attach here.

        auth('vela')->login($user);

        return redirect()->route('vela.admin.home');
    }

    /**
     * Public self-registration is opt-in. When it is off, the routes stay
     * registered (so route() and Route::has() keep working) but behave as if
     * they do not exist.
     */
    protected function abortIfDisabled(): void
    {
        abort_unless(config('vela.registration_enabled', false), 404);
    }
}
