@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.edit') }} {{ trans('vela::cruds.config.title_singular') }}
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route("vela.admin.configs.update", [$config->id]) }}" enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <div class="form-group">
                <label class="required" for="key">{{ trans('vela::cruds.config.fields.key') }}</label>
                <input class="form-control {{ $errors->has('key') ? 'is-invalid' : '' }}" type="text" name="key" id="key" value="{{ old('key', $config->key) }}" required>
                @if($errors->has('key'))
                    <div class="invalid-feedback">
                        {{ $errors->first('key') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.config.fields.key_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="value">{{ trans('vela::cruds.config.fields.value') }}</label>
                <input class="form-control {{ $errors->has('value') ? 'is-invalid' : '' }}" type="text" name="value" id="value" value="{{ old('value', $config->value) }}">
                @if($errors->has('value'))
                    <div class="invalid-feedback">
                        {{ $errors->first('value') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.config.fields.value_helper') }}</span>
            </div>
            <div class="form-group">
                <button class="btn btn-danger" type="submit">
                    {{ trans('vela::global.save') }}
                </button>
            </div>
        </form>
    </div>
</div>



@endsection
