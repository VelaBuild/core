@extends('vela::layouts.admin')

@section('breadcrumb', 'Edit translation')

@section('content')
<x-vela::edit-page
    :title="$translation->model_key"
    :subtitle="$translation->lang_code . ' · ' . $translation->model_type"
    :breadcrumb="[
        ['label' => trans('vela::cruds.translation.title'), 'url' => route('vela.admin.translations.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    avatar-fallback="{{ mb_substr($translation->lang_code, 0, 2) }}"
    :action="route('vela.admin.translations.update', $translation->id)"
    method="PUT"
    :cancel-url="route('vela.admin.translations.index')"
>
    <x-slot name="main">
        <x-vela::section title="{{ trans('vela::cruds.translation.title_singular') }}" description="The translated content.">
            <div class="form-group">
                <label for="translation">{{ trans('vela::cruds.translation.fields.translation') }}</label>
                <textarea class="form-control {{ $errors->has('translation') ? 'is-invalid' : '' }}" name="translation" id="translation" rows="6">{{ old('translation', $translation->translation) }}</textarea>
                @if($errors->has('translation'))<div class="invalid-feedback">{{ $errors->first('translation') }}</div>@endif
            </div>

            <div class="form-group mb-0">
                <label for="notes">{{ trans('vela::cruds.translation.fields.notes') }}</label>
                <input class="form-control {{ $errors->has('notes') ? 'is-invalid' : '' }}" type="text" name="notes" id="notes" value="{{ old('notes', $translation->notes) }}">
                @if($errors->has('notes'))<div class="invalid-feedback">{{ $errors->first('notes') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.translation.fields.notes_helper') }}</small>
            </div>
        </x-vela::section>
    </x-slot>

    <x-slot name="side">
        <x-vela::meta-card title="Identity" :body-padding="false">
            <dl class="vela-meta-list">
                <dt>{{ trans('vela::cruds.translation.fields.lang_code') }}</dt>
                <dd><input class="form-control {{ $errors->has('lang_code') ? 'is-invalid' : '' }}" type="text" name="lang_code" id="lang_code" value="{{ old('lang_code', $translation->lang_code) }}" required></dd>

                <dt>{{ trans('vela::cruds.translation.fields.model_type') }}</dt>
                <dd><input class="form-control {{ $errors->has('model_type') ? 'is-invalid' : '' }}" type="text" name="model_type" id="model_type" value="{{ old('model_type', $translation->model_type) }}" required></dd>

                <dt>{{ trans('vela::cruds.translation.fields.model_key') }}</dt>
                <dd><input class="form-control {{ $errors->has('model_key') ? 'is-invalid' : '' }}" type="text" name="model_key" id="model_key" value="{{ old('model_key', $translation->model_key) }}" required></dd>
            </dl>
        </x-vela::meta-card>
    </x-slot>
</x-vela::edit-page>
@endsection
