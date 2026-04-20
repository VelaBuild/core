@extends('vela::layouts.admin')

@section('breadcrumb', $permission->title . ' — Edit permission')

@section('content')
<x-vela::edit-page
    :title="$permission->title"
    :subtitle="trans('vela::cruds.permission.title_singular')"
    :breadcrumb="[
        ['label' => trans('vela::cruds.permission.title'), 'url' => route('vela.admin.permissions.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    :avatar-fallback="mb_substr($permission->title, 0, 1)"
    :action="route('vela.admin.permissions.update', $permission->id)"
    method="PUT"
    :cancel-url="route('vela.admin.permissions.index')"
>
    <x-vela::section title="{{ trans('vela::cruds.permission.title_singular') }}">
        <div class="form-group mb-0">
            <label class="required" for="title">{{ trans('vela::cruds.permission.fields.title') }}</label>
            <input class="form-control {{ $errors->has('title') ? 'is-invalid' : '' }}" type="text" name="title" id="title" value="{{ old('title', $permission->title) }}" required>
            @if($errors->has('title'))<div class="invalid-feedback">{{ $errors->first('title') }}</div>@endif
            <small class="form-text text-muted">{{ trans('vela::cruds.permission.fields.title_helper') }}</small>
        </div>
    </x-vela::section>
</x-vela::edit-page>
@endsection
