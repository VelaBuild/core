@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.edit') }} {{ trans('vela::cruds.translation.title_singular') }}
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route("vela.admin.translations.update", [$translation->id]) }}" enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <div class="form-group">
                <label class="required" for="lang_code">{{ trans('vela::cruds.translation.fields.lang_code') }}</label>
                <input class="form-control {{ $errors->has('lang_code') ? 'is-invalid' : '' }}" type="text" name="lang_code" id="lang_code" value="{{ old('lang_code', $translation->lang_code) }}" required>
                @if($errors->has('lang_code'))
                    <div class="invalid-feedback">
                        {{ $errors->first('lang_code') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.translation.fields.lang_code_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required" for="model_type">{{ trans('vela::cruds.translation.fields.model_type') }}</label>
                <input class="form-control {{ $errors->has('model_type') ? 'is-invalid' : '' }}" type="text" name="model_type" id="model_type" value="{{ old('model_type', $translation->model_type) }}" required>
                @if($errors->has('model_type'))
                    <div class="invalid-feedback">
                        {{ $errors->first('model_type') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.translation.fields.model_type_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required" for="model_key">{{ trans('vela::cruds.translation.fields.model_key') }}</label>
                <input class="form-control {{ $errors->has('model_key') ? 'is-invalid' : '' }}" type="text" name="model_key" id="model_key" value="{{ old('model_key', $translation->model_key) }}" required>
                @if($errors->has('model_key'))
                    <div class="invalid-feedback">
                        {{ $errors->first('model_key') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.translation.fields.model_key_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="translation">{{ trans('vela::cruds.translation.fields.translation') }}</label>
                <textarea class="form-control {{ $errors->has('translation') ? 'is-invalid' : '' }}" name="translation" id="translation">{{ old('translation', $translation->translation) }}</textarea>
                @if($errors->has('translation'))
                    <div class="invalid-feedback">
                        {{ $errors->first('translation') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.translation.fields.translation_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="notes">{{ trans('vela::cruds.translation.fields.notes') }}</label>
                <input class="form-control {{ $errors->has('notes') ? 'is-invalid' : '' }}" type="text" name="notes" id="notes" value="{{ old('notes', $translation->notes) }}">
                @if($errors->has('notes'))
                    <div class="invalid-feedback">
                        {{ $errors->first('notes') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.translation.fields.notes_helper') }}</span>
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
