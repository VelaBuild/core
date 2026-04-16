<?php

namespace VelaBuild\Core\Http\Requests;

use Gate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;

class StoreIdeaRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('idea_create');
    }

    public function rules()
    {
        return [
            'name' => [
                'string',
                'required',
            ],
            'keyword' => [
                'nullable',
                'string',
                'max:255',
            ],
            'status' => [
                'required',
            ],
            'category_id' => [
                'nullable',
                'integer',
                'exists:vela_categories,id',
            ],
        ];
    }
}
