@extends('vela::layouts.admin')
@section('content')

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                {{ trans('vela::global.my_profile') }}
            </div>

            <div class="card-body">
                <form method="POST" action="{{ route("vela.auth.profile.password.updateProfile") }}">
                    @csrf

                    <div class="form-group text-center">
                        <img src="{{ auth('vela')->user()->getAvatarUrl(120) }}" alt="{{ auth('vela')->user()->name }}" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid #e5e7eb;" id="profile-avatar-preview">
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
                    </div>

                    <div class="form-group">
                        <label class="required" for="name">{{ trans('vela::cruds.user.fields.name') }}</label>
                        <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', auth('vela')->user()->name) }}" required>
                        @if($errors->has('name'))
                            <div class="invalid-feedback">
                                {{ $errors->first('name') }}
                            </div>
                        @endif
                    </div>
                    <div class="form-group">
                        <label class="required" for="title">{{ trans('vela::cruds.user.fields.email') }}</label>
                        <input class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" type="text" name="email" id="email" value="{{ old('email', auth('vela')->user()->email) }}" required>
                        @if($errors->has('email'))
                            <div class="invalid-feedback">
                                {{ $errors->first('email') }}
                            </div>
                        @endif
                    </div>
                    <div class="form-group">
                        <button class="btn btn-danger" type="submit">
                            {{ trans('vela::global.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                {{ trans('vela::global.change_password') }}
            </div>

            <div class="card-body">
                <form method="POST" action="{{ route("vela.auth.profile.password.update") }}">
                    @csrf
                    <div class="form-group">
                        <label class="required" for="title">{{ trans('vela::global.new_password') }}</label>
                        <input class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}" type="password" name="password" id="password" required>
                        @if($errors->has('password'))
                            <div class="invalid-feedback">
                                {{ $errors->first('password') }}
                            </div>
                        @endif
                    </div>
                    <div class="form-group">
                        <label class="required" for="title">{{ trans('vela::global.repeat_new_password') }}</label>
                        <input class="form-control" type="password" name="password_confirmation" id="password_confirmation" required>
                    </div>
                    <div class="form-group">
                        <button class="btn btn-danger" type="submit">
                            {{ trans('vela::global.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                {{ trans('vela::global.delete_account') }}
            </div>

            <div class="card-body">
                <form method="POST" action="{{ route("vela.auth.profile.password.destroyProfile") }}" onsubmit="return prompt('{{ __('vela::global.delete_account_warning') }}') == '{{ auth('vela')->user()->email }}'">
                    @csrf
                    <div class="form-group">
                        <button class="btn btn-danger" type="submit">
                            {{ trans('vela::global.delete') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @if(Route::has('vela.auth.profile.password.toggleTwoFactor'))
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    {{ trans('vela::global.two_factor.title') }}
                </div>

                <div class="card-body">
                    <form method="POST" action="{{ route("vela.auth.profile.password.toggleTwoFactor") }}">
                        @csrf
                        <div class="form-group">
                            <button class="btn btn-danger" type="submit">
                                {{ auth('vela')->user()->two_factor ? trans('vela::global.two_factor.disable') : trans('vela::global.two_factor.enable') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
  var uploadedProfilePicMap = {};
  Dropzone.options.profilePicDropzone = {
    url: '{{ route('vela.auth.profile.password.storeMedia') }}',
    maxFilesize: 2, // MB
    acceptedFiles: '.jpeg,.jpg,.png,.gif',
    maxFiles: 1,
    addRemoveLinks: true,
    headers: {
      'X-CSRF-TOKEN': "{{ csrf_token() }}"
    },
    params: {
      size: 2,
      width: 2000,
      height: 2000
    },
    success: function (file, response) {
      $('form').first().find('input[name="profile_pic"]').remove();
      $('form').first().append('<input type="hidden" name="profile_pic" value="' + response.name + '">');
      if (file.dataURL) {
        document.getElementById('profile-avatar-preview').src = file.dataURL;
      }
    },
    removedfile: function (file) {
      file.previewElement.remove();
      if (file.status !== 'error') {
        $('form').first().find('input[name="profile_pic"]').remove();
        this.options.maxFiles = this.options.maxFiles + 1;
      }
    },
    init: function () {
@if(auth('vela')->user()->profile_pic)
      var file = {!! json_encode(auth('vela')->user()->profile_pic) !!}
      this.options.addedfile.call(this, file);
      this.options.thumbnail.call(this, file, file.preview ?? file.preview_url);
      file.previewElement.classList.add('dz-complete');
      $('form').first().append('<input type="hidden" name="profile_pic" value="' + file.file_name + '">');
      this.options.maxFiles = this.options.maxFiles - 1;
@endif
    },
    error: function (file, response) {
        if ($.type(response) === 'string') {
            var message = response;
        } else {
            var message = response.errors.file;
        }
        file.previewElement.classList.add('dz-error');
        _ref = file.previewElement.querySelectorAll('[data-dz-errormessage]');
        _results = [];
        for (_i = 0, _len = _ref.length; _i < _len; _i++) {
            node = _ref[_i];
            _results.push(node.textContent = message);
        }
        return _results;
    }
  };
</script>
@endsection
