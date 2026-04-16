<?php

namespace VelaBuild\Core\Http\Requests;

use VelaBuild\Core\Models\Page;
use Gate;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePageRequest extends FormRequest
{
    public function authorize()
    {
        return Gate::allows('page_edit');
    }

    public function rules()
    {
        return [
            'title'            => 'required|string|max:255',
            'slug'             => [
                'required', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'not_in:' . implode(',', Page::RESERVED_SLUGS),
                'unique:vela_pages,slug,' . $this->route('page')->id . ',id,locale,' . $this->input('locale', 'en'),
            ],
            'locale'           => 'required|string|max:10',
            'status'           => 'required|in:draft,published,unlisted',
            'meta_title'       => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'custom_css'       => 'nullable|string|max:65000',
            'custom_js'        => 'nullable|string|max:65000',
            'order_column'     => 'nullable|integer',
            'parent_id'        => 'nullable|integer|exists:vela_pages,id',
            'rows'             => 'nullable|string',
        ];
    }
}
