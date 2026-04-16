@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.edit') }} {{ trans('vela::cruds.user.title_singular') }}
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route("vela.admin.users.update", [$user->id]) }}" enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <div class="form-group">
                <label class="required" for="name">{{ trans('vela::cruds.user.fields.name') }}</label>
                <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required>
                @if($errors->has('name'))
                    <div class="invalid-feedback">
                        {{ $errors->first('name') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.name_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required" for="email">{{ trans('vela::cruds.user.fields.email') }}</label>
                <input class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required>
                @if($errors->has('email'))
                    <div class="invalid-feedback">
                        {{ $errors->first('email') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.email_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required" for="password">{{ trans('vela::cruds.user.fields.password') }}</label>
                <input class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}" type="password" name="password" id="password">
                @if($errors->has('password'))
                    <div class="invalid-feedback">
                        {{ $errors->first('password') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.password_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required" for="roles">{{ trans('vela::cruds.user.fields.roles') }}</label>
                <div style="padding-bottom: 4px">
                    <span class="btn btn-info btn-xs select-all" style="border-radius: 0">{{ trans('vela::global.select_all') }}</span>
                    <span class="btn btn-info btn-xs deselect-all" style="border-radius: 0">{{ trans('vela::global.deselect_all') }}</span>
                </div>
                <select class="form-control select2 {{ $errors->has('roles') ? 'is-invalid' : '' }}" name="roles[]" id="roles" multiple required>
                    @foreach($roles as $id => $role)
                        <option value="{{ $id }}" {{ (in_array($id, old('roles', [])) || $user->roles->contains($id)) ? 'selected' : '' }}>{{ $role }}</option>
                    @endforeach
                </select>
                @if($errors->has('roles'))
                    <div class="invalid-feedback">
                        {{ $errors->first('roles') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.roles_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="last_login_at">{{ trans('vela::cruds.user.fields.last_login_at') }}</label>
                <input class="form-control date {{ $errors->has('last_login_at') ? 'is-invalid' : '' }}" type="text" name="last_login_at" id="last_login_at" value="{{ old('last_login_at', $user->last_login_at) }}">
                @if($errors->has('last_login_at'))
                    <div class="invalid-feedback">
                        {{ $errors->first('last_login_at') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.last_login_at_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="last_ip">{{ trans('vela::cruds.user.fields.last_ip') }}</label>
                <input class="form-control {{ $errors->has('last_ip') ? 'is-invalid' : '' }}" type="text" name="last_ip" id="last_ip" value="{{ old('last_ip', $user->last_ip) }}">
                @if($errors->has('last_ip'))
                    <div class="invalid-feedback">
                        {{ $errors->first('last_ip') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.last_ip_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="useragent">{{ trans('vela::cruds.user.fields.useragent') }}</label>
                <input class="form-control {{ $errors->has('useragent') ? 'is-invalid' : '' }}" type="text" name="useragent" id="useragent" value="{{ old('useragent', $user->useragent) }}">
                @if($errors->has('useragent'))
                    <div class="invalid-feedback">
                        {{ $errors->first('useragent') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.useragent_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="profile_pic">{{ trans('vela::cruds.user.fields.profile_pic') }}</label>
                <div class="needsclick dropzone {{ $errors->has('profile_pic') ? 'is-invalid' : '' }}" id="profile_pic-dropzone">
                </div>
                @if($errors->has('profile_pic'))
                    <div class="invalid-feedback">
                        {{ $errors->first('profile_pic') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.profile_pic_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="bio">{{ trans('vela::cruds.user.fields.bio') }}</label>
                <textarea class="form-control ckeditor {{ $errors->has('bio') ? 'is-invalid' : '' }}" name="bio" id="bio">{!! old('bio', $user->bio) !!}</textarea>
                @if($errors->has('bio'))
                    <div class="invalid-feedback">
                        {{ $errors->first('bio') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.bio_helper') }}</span>
            </div>
            <div class="form-group">
                <div class="form-check {{ $errors->has('subscribe_newsletter') ? 'is-invalid' : '' }}">
                    <input type="hidden" name="subscribe_newsletter" value="0">
                    <input class="form-check-input" type="checkbox" name="subscribe_newsletter" id="subscribe_newsletter" value="1" {{ $user->subscribe_newsletter || old('subscribe_newsletter', 0) === 1 ? 'checked' : '' }}>
                    <label class="form-check-label" for="subscribe_newsletter">{{ trans('vela::cruds.user.fields.subscribe_newsletter') }}</label>
                </div>
                @if($errors->has('subscribe_newsletter'))
                    <div class="invalid-feedback">
                        {{ $errors->first('subscribe_newsletter') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.user.fields.subscribe_newsletter_helper') }}</span>
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

@section('scripts')
<script>
    Dropzone.options.profilePicDropzone = {
    url: '{{ route('vela.admin.users.storeMedia') }}',
    maxFilesize: 20, // MB
    acceptedFiles: '.jpeg,.jpg,.png,.gif',
    maxFiles: 1,
    addRemoveLinks: true,
    headers: {
      'X-CSRF-TOKEN': "{{ csrf_token() }}"
    },
    params: {
      size: 20,
      width: 2000,
      height: 2000
    },
    success: function (file, response) {
      $('form').find('input[name="profile_pic"]').remove()
      $('form').append('<input type="hidden" name="profile_pic" value="' + response.name + '">')
    },
    removedfile: function (file) {
      file.previewElement.remove()
      if (file.status !== 'error') {
        $('form').find('input[name="profile_pic"]').remove()
        this.options.maxFiles = this.options.maxFiles + 1
      }
    },
    init: function () {
@if(isset($user) && $user->profile_pic)
      var file = {!! json_encode($user->profile_pic) !!}
          this.options.addedfile.call(this, file)
      this.options.thumbnail.call(this, file, file.preview ?? file.preview_url)
      file.previewElement.classList.add('dz-complete')
      $('form').append('<input type="hidden" name="profile_pic" value="' + file.file_name + '">')
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
<script>
    $(document).ready(function () {
  function SimpleUploadAdapter(editor) {
    editor.plugins.get('FileRepository').createUploadAdapter = function(loader) {
      return {
        upload: function() {
          return loader.file
            .then(function (file) {
              return new Promise(function(resolve, reject) {
                // Init request
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '{{ route('vela.admin.users.storeCKEditorImages') }}', true);
                xhr.setRequestHeader('x-csrf-token', window._token);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.responseType = 'json';

                // Init listeners
                var genericErrorText = `Couldn't upload file: ${ file.name }.`;
                xhr.addEventListener('error', function() { reject(genericErrorText) });
                xhr.addEventListener('abort', function() { reject() });
                xhr.addEventListener('load', function() {
                  var response = xhr.response;

                  if (!response || xhr.status !== 201) {
                    return reject(response && response.message ? `${genericErrorText}\n${xhr.status} ${response.message}` : `${genericErrorText}\n ${xhr.status} ${xhr.statusText}`);
                  }

                  $('form').append('<input type="hidden" name="ck-media[]" value="' + response.id + '">');

                  resolve({ default: response.url });
                });

                if (xhr.upload) {
                  xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                      loader.uploadTotal = e.total;
                      loader.uploaded = e.loaded;
                    }
                  });
                }

                // Send request
                var data = new FormData();
                data.append('upload', file);
                data.append('crud_id', '{{ $user->id ?? 0 }}');
                xhr.send(data);
              });
            })
        }
      };
    }
  }

  var allEditors = document.querySelectorAll('.ckeditor');
  for (var i = 0; i < allEditors.length; ++i) {
    ClassicEditor.create(
      allEditors[i], {
        extraPlugins: [SimpleUploadAdapter]
      }
    );
  }
});
</script>

@endsection
