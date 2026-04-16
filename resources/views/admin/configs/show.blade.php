@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.config.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.configs.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.config.fields.id') }}
                        </th>
                        <td>
                            {{ $config->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.config.fields.key') }}
                        </th>
                        <td>
                            {{ $config->key }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.config.fields.value') }}
                        </th>
                        <td>
                            {{ $config->value }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.configs.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
        </div>
    </div>
</div>



@endsection
