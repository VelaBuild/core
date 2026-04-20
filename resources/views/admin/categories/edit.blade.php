@extends('vela::layouts.admin')

@section('breadcrumb', $category->name . ' — Edit category')

@section('content')
<x-vela::edit-page
    :title="$category->name"
    :subtitle="trans('vela::cruds.category.title_singular')"
    :breadcrumb="[
        ['label' => trans('vela::cruds.category.title'), 'url' => route('vela.admin.categories.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    :avatar="$category->image->preview ?? $category->image->preview_url ?? null"
    :avatar-fallback="mb_substr($category->name, 0, 1)"
    :action="route('vela.admin.categories.update', $category->id)"
    method="PUT"
    :cancel-url="route('vela.admin.categories.index')"
>
    <x-slot name="main">
        <x-vela::section title="{{ trans('vela::global.basic_information') }}" description="Name + icon class.">
            <div class="form-group">
                <label class="required" for="name">{{ trans('vela::cruds.category.fields.name') }}</label>
                <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', $category->name) }}" required>
                @if($errors->has('name'))<div class="invalid-feedback">{{ $errors->first('name') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.category.fields.name_helper') }}</small>
            </div>
            <div class="form-group mb-0">
                <label for="icon">{{ trans('vela::cruds.category.fields.icon') }}</label>
                <input class="form-control {{ $errors->has('icon') ? 'is-invalid' : '' }}" type="text" name="icon" id="icon" value="{{ old('icon', $category->icon) }}" placeholder="fas fa-folder">
                @if($errors->has('icon'))<div class="invalid-feedback">{{ $errors->first('icon') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.category.fields.icon_helper') }}</small>
            </div>
        </x-vela::section>
    </x-slot>

    <x-slot name="side">
        <x-vela::meta-card title="{{ trans('vela::global.settings') }}">
            <div class="form-group">
                <label for="order_by">{{ trans('vela::cruds.category.fields.order_by') }}</label>
                <input class="form-control {{ $errors->has('order_by') ? 'is-invalid' : '' }}" type="number" name="order_by" id="order_by" value="{{ old('order_by', $category->order_by) }}" step="1">
                @if($errors->has('order_by'))<div class="invalid-feedback">{{ $errors->first('order_by') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.category.fields.order_by_helper') }}</small>
            </div>

            <div class="form-group mb-0">
                <label for="image">{{ trans('vela::cruds.category.fields.image') }}</label>
                <div class="needsclick dropzone {{ $errors->has('image') ? 'is-invalid' : '' }}" id="image-dropzone"></div>
                @if($errors->has('image'))<div class="invalid-feedback">{{ $errors->first('image') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.category.fields.image_helper') }}</small>
            </div>
        </x-vela::meta-card>
    </x-slot>
</x-vela::edit-page>
@endsection

@section('scripts')
<script>
Dropzone.options.imageDropzone = {
    url: '{{ route('vela.admin.categories.storeMedia') }}',
    maxFilesize: 5, acceptedFiles: '.jpeg,.jpg,.png,.gif', maxFiles: 1, addRemoveLinks: true,
    headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
    params: { size: 5, width: 4096, height: 4096 },
    success: function (file, response) {
        $('form').find('input[name="image"]').remove()
        $('form').append('<input type="hidden" name="image" value="' + response.name + '">')
    },
    removedfile: function (file) {
        file.previewElement.remove()
        if (file.status !== 'error') {
            $('form').find('input[name="image"]').remove()
            this.options.maxFiles = this.options.maxFiles + 1
        }
    },
    init: function () {
@if(isset($category) && $category->image)
        var file = {!! json_encode($category->image) !!}
        this.options.addedfile.call(this, file)
        this.options.thumbnail.call(this, file, file.preview ?? file.preview_url)
        file.previewElement.classList.add('dz-complete')
        $('form').append('<input type="hidden" name="image" value="' + file.file_name + '">')
        this.options.maxFiles = this.options.maxFiles - 1
@endif
    },
    error: function (file, response) {
        var message = $.type(response) === 'string' ? response : response.errors.file
        file.previewElement.classList.add('dz-error')
        var _ref = file.previewElement.querySelectorAll('[data-dz-errormessage]')
        for (var _i = 0; _i < _ref.length; _i++) _ref[_i].textContent = message
    }
}
</script>
@endsection
