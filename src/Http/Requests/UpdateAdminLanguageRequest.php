<?php

namespace VelaBuild\Core\Http\Requests;

use Gate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use VelaBuild\Core\Services\AdminLanguage;

/**
 * The signed-in account choosing its own admin language on the profile page.
 * Empty means the site's default admin language.
 */
class UpdateAdminLanguageRequest extends FormRequest
{
    public function authorize()
    {
        abort_if(Gate::denies('profile_password_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return true;
    }

    public function rules()
    {
        return [
            'admin_locale' => ['nullable', 'string', Rule::in(array_keys(app(AdminLanguage::class)->available()))],
        ];
    }
}
