<?php

namespace VelaBuild\Core\Http\Requests;

use Gate;
use Illuminate\Foundation\Http\FormRequest;

class StoreTranslationRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('translation_create');
    }

    public function rules()
    {
        return [
            'lang_code' => [
                'string',
                'max:5',
                'required',
            ],
            'model_type' => [
                'string',
                'required',
            ],
            'model_key' => [
                'string',
                'required',
            ],
            'notes' => [
                'string',
                'nullable',
            ],
        ];
    }
}
