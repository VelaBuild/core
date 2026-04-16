@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.translation.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.translations.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.translation.fields.id') }}
                        </th>
                        <td>
                            {{ $translation->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.translation.fields.lang_code') }}
                        </th>
                        <td>
                            {{ $translation->lang_code }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.translation.fields.model_type') }}
                        </th>
                        <td>
                            {{ $translation->model_type }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.translation.fields.model_key') }}
                        </th>
                        <td>
                            {{ $translation->model_key }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.translation.fields.translation') }}
                        </th>
                        <td>
                            {{ $translation->translation }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.translation.fields.notes') }}
                        </th>
                        <td>
                            {{ $translation->notes }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.translations.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
        </div>
    </div>
</div>



@endsection
