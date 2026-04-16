<?php

namespace VelaBuild\Core\Http\Requests;

use Gate;
use Illuminate\Foundation\Http\FormRequest;

class StoreConfigRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('config_create');
    }

    public function rules()
    {
        return [
            'key' => [
                'string',
                'required',
            ],
            'value' => [
                'string',
                'nullable',
            ],
        ];
    }
}
