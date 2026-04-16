<?php

namespace VelaBuild\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MassDestroyFormSubmissionRequest extends FormRequest
{
    public function authorize()
    {
        abort_if(Gate::denies('form_submission_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return true;
    }

    public function rules()
    {
        return [
            'ids'   => ['required', 'array'],
            'ids.*' => ['exists:vela_form_submissions,id'],
        ];
    }
}
