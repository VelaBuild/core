<?php

namespace VelaBuild\Core\Http\Controllers\Auth;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\MediaUploadingTrait;
use VelaBuild\Core\Http\Requests\UpdatePasswordRequest;
use VelaBuild\Core\Http\Requests\UpdateProfileRequest;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChangePasswordController extends Controller
{
    use MediaUploadingTrait;
    public function edit()
    {
        abort_if(Gate::denies('profile_password_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::auth.passwords.edit');
    }

    public function update(UpdatePasswordRequest $request)
    {
        auth('vela')->user()->update($request->validated());

        return redirect()->route('vela.auth.profile.password.edit')->with('message', __('vela::global.change_password_success'));
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = auth('vela')->user();

        $user->update($request->validated());

        if ($request->input('profile_pic', false)) {
            if (! $user->profile_pic || $request->input('profile_pic') !== $user->profile_pic->file_name) {
                if ($user->profile_pic) {
                    $user->profile_pic->delete();
                }
                $user->addMedia(storage_path('tmp/uploads/' . basename($request->input('profile_pic'))))->toMediaCollection('profile_pic');
            }
        } elseif ($user->profile_pic) {
            $user->profile_pic->delete();
        }

        return redirect()->route('vela.auth.profile.password.edit')->with('message', __('vela::global.update_profile_success'));
    }

    public function destroy()
    {
        $user = auth('vela')->user();

        $user->update([
            'email' => time() . '_' . $user->email,
        ]);

        $user->delete();

        return redirect()->route('vela.auth.login')->with('message', __('vela::global.delete_account_success'));
    }

    public function toggleTwoFactor(Request $request)
    {
        $user = auth('vela')->user();

        if ($user->two_factor) {
            $message = __('vela::global.two_factor.disabled');
        } else {
            $message = __('vela::global.two_factor.enabled');
        }

        $user->two_factor = ! $user->two_factor;

        $user->save();

        return redirect()->route('vela.auth.profile.password.edit')->with('message', $message);
    }
}
