<?php

namespace VelaBuild\Core\Http\Requests;

use Gate;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('user_create');
    }

    public function rules()
    {
        // last_login_at / last_ip / useragent are audit fields — set
        // automatically on login, never accepted from the admin form.
        // Same belt-and-braces guard exists in UsersController::store().
        return [
            'name' => [
                'string',
                'required',
            ],
            'email' => [
                'required',
                'unique:vela_users',
            ],
            'password' => [
                'required',
            ],
            'roles.*' => [
                'integer',
            ],
            'roles' => [
                'required',
                'array',
            ],
        ];
    }
}
