@extends('vela::layouts.admin')

@section('breadcrumb', $idea->name . ' — Edit idea')

@section('content')
<x-vela::edit-page
    :title="$idea->name"
    subtitle="{{ trans('vela::cruds.idea.title_singular') }}"
    :breadcrumb="[
        ['label' => trans('vela::cruds.idea.title'), 'url' => route('vela.admin.ideas.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    avatar-fallback="{{ mb_substr($idea->name, 0, 1) }}"
    :action="route('vela.admin.ideas.update', $idea->id)"
    method="PUT"
    :cancel-url="route('vela.admin.ideas.index')"
>
    <x-slot name="main">
        <x-vela::section title="{{ trans('vela::cruds.idea.title_singular') }}" description="Name, details, keyword.">
            <div class="form-group">
                <label class="required" for="name">{{ trans('vela::cruds.idea.fields.name') }}</label>
                <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', $idea->name) }}" required>
                @if($errors->has('name'))<div class="invalid-feedback">{{ $errors->first('name') }}</div>@endif
            </div>

            <div class="form-group">
                <label for="details">{{ trans('vela::cruds.idea.fields.details') }}</label>
                <textarea class="form-control {{ $errors->has('details') ? 'is-invalid' : '' }}" name="details" id="details" rows="4">{{ old('details', $idea->details) }}</textarea>
                @if($errors->has('details'))<div class="invalid-feedback">{{ $errors->first('details') }}</div>@endif
            </div>

            <div class="form-group mb-0">
                <label for="keyword">{{ trans('vela::cruds.idea.fields.keyword') }}</label>
                <input type="text" class="form-control {{ $errors->has('keyword') ? 'is-invalid' : '' }}" name="keyword" id="keyword" value="{{ old('keyword', $idea->keyword) }}">
                @if($errors->has('keyword'))<div class="invalid-feedback">{{ $errors->first('keyword') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.idea.fields.keyword_helper') }}</small>
            </div>
        </x-vela::section>
    </x-slot>

    <x-slot name="side">
        <x-vela::meta-card title="Status">
            <div class="form-group mb-0">
                <select class="form-control {{ $errors->has('status') ? 'is-invalid' : '' }}" name="status" id="status" required>
                    <option value disabled {{ old('status', null) === null ? 'selected' : '' }}>{{ trans('vela::global.pleaseSelect') }}</option>
                    @foreach(\VelaBuild\Core\Models\Idea::STATUS_SELECT as $key => $label)
                        <option value="{{ $key }}" {{ old('status', $idea->status) === (string) $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                    @endforeach
                </select>
                @if($errors->has('status'))<div class="invalid-feedback">{{ $errors->first('status') }}</div>@endif
            </div>
        </x-vela::meta-card>

        <x-vela::meta-card title="Category">
            <div class="form-group mb-0">
                <select class="form-control {{ $errors->has('category_id') ? 'is-invalid' : '' }}" name="category_id" id="category_id">
                    <option value="">{{ trans('vela::global.pleaseSelect') }}</option>
                    @foreach(\VelaBuild\Core\Models\Category::all() as $category)
                        <option value="{{ $category->id }}" {{ old('category_id', $idea->category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                    @endforeach
                </select>
                @if($errors->has('category_id'))<div class="invalid-feedback">{{ $errors->first('category_id') }}</div>@endif
            </div>
        </x-vela::meta-card>
    </x-slot>
</x-vela::edit-page>
@endsection
