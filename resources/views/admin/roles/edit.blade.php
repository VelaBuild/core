@extends('vela::layouts.admin')

@section('breadcrumb', $role->title . ' — Edit role')

@section('content')
<x-vela::edit-page
    :title="$role->title"
    :subtitle="trans('vela::cruds.role.title_singular')"
    :breadcrumb="[
        ['label' => trans('vela::cruds.role.title'), 'url' => route('vela.admin.roles.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    :avatar-fallback="mb_substr($role->title, 0, 1)"
    :action="route('vela.admin.roles.update', $role->id)"
    method="PUT"
    :cancel-url="route('vela.admin.roles.index')"
>
    <x-vela::section title="{{ trans('vela::cruds.role.title_singular') }}" description="Name + the permissions this role grants.">
        <div class="form-group">
            <label class="required" for="title">{{ trans('vela::cruds.role.fields.title') }}</label>
            <input class="form-control {{ $errors->has('title') ? 'is-invalid' : '' }}" type="text" name="title" id="title" value="{{ old('title', $role->title) }}" required>
            @if($errors->has('title'))<div class="invalid-feedback">{{ $errors->first('title') }}</div>@endif
            <small class="form-text text-muted">{{ trans('vela::cruds.role.fields.title_helper') }}</small>
        </div>

        <div class="form-group mb-0">
            <label class="required" for="permissions">{{ trans('vela::cruds.role.fields.permissions') }}</label>
            <div class="vela-select-toolbar mb-1">
                <button type="button" class="btn btn-xs btn-secondary select-all">{{ trans('vela::global.select_all') }}</button>
                <button type="button" class="btn btn-xs btn-secondary deselect-all">{{ trans('vela::global.deselect_all') }}</button>
            </div>
            <select class="form-control select2 {{ $errors->has('permissions') ? 'is-invalid' : '' }}" name="permissions[]" id="permissions" multiple required>
                @foreach($permissions as $id => $permission)
                    <option value="{{ $id }}" {{ (in_array($id, old('permissions', [])) || $role->permissions->contains($id)) ? 'selected' : '' }}>{{ $permission }}</option>
                @endforeach
            </select>
            @if($errors->has('permissions'))<div class="invalid-feedback">{{ $errors->first('permissions') }}</div>@endif
            <small class="form-text text-muted">{{ trans('vela::cruds.role.fields.permissions_helper') }}</small>
        </div>
    </x-vela::section>
</x-vela::edit-page>
@endsection
