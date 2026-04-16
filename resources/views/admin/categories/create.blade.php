@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.create') }} {{ trans('vela::cruds.category.title_singular') }}
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route("vela.admin.categories.store") }}" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-lg-8">
                    <div class="form-section-title">{{ trans('vela::global.basic_information') }}</div>
                    <div class="form-group">
                        <label class="required" for="name">{{ trans('vela::cruds.category.fields.name') }}</label>
                        <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', '') }}" required>
                        @if($errors->has('name'))
                            <div class="invalid-feedback">
                                {{ $errors->first('name') }}
                            </div>
                        @endif
                        <span class="help-block">{{ trans('vela::cruds.category.fields.name_helper') }}</span>
                    </div>
                    <div class="form-group">
                        <label for="icon">{{ trans('vela::cruds.category.fields.icon') }}</label>
                        <input class="form-control {{ $errors->has('icon') ? 'is-invalid' : '' }}" type="text" name="icon" id="icon" value="{{ old('icon', '') }}">
                        @if($errors->has('icon'))
                            <div class="invalid-feedback">
                                {{ $errors->first('icon') }}
                            </div>
                        @endif
                        <span class="help-block">{{ trans('vela::cruds.category.fields.icon_helper') }}</span>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="sticky-panel">
                        <div class="form-section-title">{{ trans('vela::global.settings') }}</div>
                        <div class="form-group">
                            <label for="order_by">{{ trans('vela::cruds.category.fields.order_by') }}</label>
                            <input class="form-control {{ $errors->has('order_by') ? 'is-invalid' : '' }}" type="number" name="order_by" id="order_by" value="{{ old('order_by', '') }}" step="1">
                            @if($errors->has('order_by'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('order_by') }}
                                </div>
                            @endif
                            <span class="help-block">{{ trans('vela::cruds.category.fields.order_by_helper') }}</span>
                        </div>
                        <div class="form-group">
                            <label for="image">{{ trans('vela::cruds.category.fields.image') }}</label>
                            <div class="needsclick dropzone {{ $errors->has('image') ? 'is-invalid' : '' }}" id="image-dropzone">
                            </div>
                            @if($errors->has('image'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('image') }}
                                </div>
                            @endif
                            <span class="help-block">{{ trans('vela::cruds.category.fields.image_helper') }}</span>
                        </div>
                        <div class="form-group">
                            <button class="btn btn-danger btn-block" type="submit">
                                {{ trans('vela::global.save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>



@endsection

@section('scripts')
<script>
    Dropzone.options.imageDropzone = {
    url: '{{ route('vela.admin.categories.storeMedia') }}',
    maxFilesize: 5, // MB
    acceptedFiles: '.jpeg,.jpg,.png,.gif',
    maxFiles: 1,
    addRemoveLinks: true,
    headers: {
      'X-CSRF-TOKEN': "{{ csrf_token() }}"
    },
    params: {
      size: 5,
      width: 4096,
      height: 4096
    },
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
        if ($.type(response) === 'string') {
            var message = response //dropzone sends it's own error messages in string
        } else {
            var message = response.errors.file
        }
        file.previewElement.classList.add('dz-error')
        _ref = file.previewElement.querySelectorAll('[data-dz-errormessage]')
        _results = []
        for (_i = 0, _len = _ref.length; _i < _len; _i++) {
            node = _ref[_i]
            _results.push(node.textContent = message)
        }

        return _results
    }
}

</script>
@endsection
