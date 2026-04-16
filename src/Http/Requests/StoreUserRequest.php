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
            'last_login_at' => [
                'date_format:' . config('vela.date_format'),
                'nullable',
            ],
            'last_ip' => [
                'string',
                'nullable',
            ],
            'useragent' => [
                'string',
                'nullable',
            ],
        ];
    }
}
