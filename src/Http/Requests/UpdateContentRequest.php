<?php

namespace VelaBuild\Core\Http\Requests;

use VelaBuild\Core\Models\Content;
use Gate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;

class UpdateContentRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('article_edit');
    }

    public function rules()
    {
        return [
            'title' => [
                'string',
                'required',
            ],
            'slug' => [
                'string',
                'nullable',
            ],

            'keyword' => [
                'nullable',
                'string',
                'max:255',
            ],
            'categories' => [
                'array',
            ],
            'categories.*' => [
                'integer',
            ],
            'gallery' => [
                'array',
            ],
            'written_at' => [
                'date_format:' . config('vela.date_format') . ' ' . config('vela.time_format'),
                'nullable',
            ],
            'approved_at' => [
                'date_format:' . config('vela.date_format') . ' ' . config('vela.time_format'),
                'nullable',
            ],
            'published_at' => [
                'date_format:' . config('vela.date_format') . ' ' . config('vela.time_format'),
                'nullable',
            ],
        ];
    }
}
