<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Self-registered accounts get the Guest role in place of the default
        // one the VelaUser "created" hook attaches, so what an admin grants the
        // User role never reaches someone who signed themselves up.
        $user = DB::transaction(function () use ($request) {
            $user = VelaUser::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $user->roles()->sync([Role::guest()->id]);

            return $user;
        });

        auth('vela')->login($user);

        return redirect()->route('vela.admin.home');
    }

    /**
     * Public self-registration is opt-in, in Settings > Visibility. When it is
     * off, the routes stay registered (so route() and Route::has() keep
     * working) but behave as if they do not exist.
     */
    protected function abortIfDisabled(): void
    {
        abort_unless(vela_registration_enabled(), 404);
    }
}
