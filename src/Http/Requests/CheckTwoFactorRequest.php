<?php

namespace VelaBuild\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;

class CheckTwoFactorRequest extends FormRequest
{
    public function authorize()
    {
        abort_if(auth('vela')->user()->two_factor_code === null,
            Response::HTTP_FORBIDDEN,
            '403 Forbidden'
        );

        return true;
    }

    public function rules()
    {
        return [
            'two_factor_code' => ['required', 'integer'],
        ];
    }
}
