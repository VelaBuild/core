@extends('vela::layouts.admin')

@section('breadcrumb', $config->key . ' — Edit config')

@section('content')
<x-vela::edit-page
    :title="$config->key"
    subtitle="{{ trans('vela::cruds.config.title_singular') }}"
    :breadcrumb="[
        ['label' => trans('vela::cruds.config.title'), 'url' => route('vela.admin.configs.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    avatar-fallback="{{ mb_substr($config->key, 0, 1) }}"
    :action="route('vela.admin.configs.update', $config->id)"
    method="PUT"
    :cancel-url="route('vela.admin.configs.index')"
>
    <x-vela::section title="{{ trans('vela::cruds.config.title_singular') }}">
        <div class="form-group">
            <label class="required" for="key">{{ trans('vela::cruds.config.fields.key') }}</label>
            <input class="form-control {{ $errors->has('key') ? 'is-invalid' : '' }}" type="text" name="key" id="key" value="{{ old('key', $config->key) }}" required>
            @if($errors->has('key'))<div class="invalid-feedback">{{ $errors->first('key') }}</div>@endif
            <small class="form-text text-muted">{{ trans('vela::cruds.config.fields.key_helper') }}</small>
        </div>
        <div class="form-group mb-0">
            <label for="value">{{ trans('vela::cruds.config.fields.value') }}</label>
            <input class="form-control {{ $errors->has('value') ? 'is-invalid' : '' }}" type="text" name="value" id="value" value="{{ old('value', $config->value) }}">
            @if($errors->has('value'))<div class="invalid-feedback">{{ $errors->first('value') }}</div>@endif
            <small class="form-text text-muted">{{ trans('vela::cruds.config.fields.value_helper') }}</small>
        </div>
    </x-vela::section>
</x-vela::edit-page>
@endsection
