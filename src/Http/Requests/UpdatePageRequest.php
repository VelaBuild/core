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
                \Illuminate\Validation\Rule::unique('vela_pages', 'slug')
                    ->ignore($this->route('page')->id)
                    ->where('locale', $this->input('locale', 'en'))
                    // A deleted page keeps its row but not its address.
                    ->whereNull('deleted_at'),
            ],
            'locale'           => 'required|string|max:10',
            'status'           => 'required|in:draft,published,unlisted',
            'meta_title'       => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            // The column is MEDIUMTEXT since the section importer landed: a
            // copied section brings its own (scoped) stylesheet, and a single
            // page can legitimately carry a few hundred KB of it. The old
            // 65000 matched the TEXT column and now rejects the very CSS the
            // importer wrote — the page could be built but never saved again.
            'custom_css'       => 'nullable|string|max:4000000',
            // custom_js is still a TEXT column, where anything past ~64KB is
            // truncated by the database without an error.
            'custom_js'        => 'nullable|string|max:65000',
            'order_column'     => 'nullable|integer',
            'parent_id'        => 'nullable|integer|exists:vela_pages,id',
            'rows'             => 'nullable|string',
            'og_image_media_id' => 'nullable|integer|exists:media,id',
            'x402_enabled'     => 'nullable|boolean',
            'x402_price_usd'   => 'nullable|numeric|min:0.001|max:1000',
        ];
    }
}
