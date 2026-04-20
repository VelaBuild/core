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

            {{-- Identity: name + email side-by-side --}}
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="required" for="name">{{ trans('vela::cruds.user.fields.name') }}</label>
                    <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required>
                    @if($errors->has('name'))<div class="invalid-feedback">{{ $errors->first('name') }}</div>@endif
                </div>
                <div class="form-group col-md-6">
                    <label class="required" for="email">{{ trans('vela::cruds.user.fields.email') }}</label>
                    <input class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required>
                    @if($errors->has('email'))<div class="invalid-feedback">{{ $errors->first('email') }}</div>@endif
                </div>
            </div>

            {{-- Security: password + roles --}}
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="password">{{ trans('vela::cruds.user.fields.password') }}</label>
                    <input class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}" type="password" name="password" id="password" placeholder="Leave blank to keep current">
                    @if($errors->has('password'))<div class="invalid-feedback">{{ $errors->first('password') }}</div>@endif
                    <small class="form-text text-muted">{{ trans('vela::cruds.user.fields.password_helper') }}</small>
                </div>
                <div class="form-group col-md-8">
                    <label class="required" for="roles">{{ trans('vela::cruds.user.fields.roles') }}</label>
                    <select class="form-control select2 {{ $errors->has('roles') ? 'is-invalid' : '' }}" name="roles[]" id="roles" multiple required>
                        @foreach($roles as $id => $role)
                            <option value="{{ $id }}" {{ (in_array($id, old('roles', [])) || $user->roles->contains($id)) ? 'selected' : '' }}>{{ $role }}</option>
                        @endforeach
                    </select>
                    @if($errors->has('roles'))<div class="invalid-feedback">{{ $errors->first('roles') }}</div>@endif
                </div>
            </div>

            {{-- Profile: picture + bio --}}
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="profile_pic">{{ trans('vela::cruds.user.fields.profile_pic') }}</label>
                    <div class="needsclick dropzone {{ $errors->has('profile_pic') ? 'is-invalid' : '' }}" id="profile_pic-dropzone"></div>
                    @if($errors->has('profile_pic'))<div class="invalid-feedback">{{ $errors->first('profile_pic') }}</div>@endif
                </div>
                <div class="form-group col-md-8">
                    <label for="bio">{{ trans('vela::cruds.user.fields.bio') }}</label>
                    <textarea class="form-control ckeditor {{ $errors->has('bio') ? 'is-invalid' : '' }}" name="bio" id="bio">{!! old('bio', $user->bio) !!}</textarea>
                    @if($errors->has('bio'))<div class="invalid-feedback">{{ $errors->first('bio') }}</div>@endif
                </div>
            </div>

            {{-- Preferences --}}
            <div class="form-group">
                <div class="form-check">
                    <input type="hidden" name="subscribe_newsletter" value="0">
                    <input class="form-check-input" type="checkbox" name="subscribe_newsletter" id="subscribe_newsletter" value="1" {{ $user->subscribe_newsletter ? 'checked' : '' }}>
                    <label class="form-check-label" for="subscribe_newsletter">{{ trans('vela::cruds.user.fields.subscribe_newsletter') }}</label>
                </div>
            </div>

            <div class="form-group mt-4 mb-0">
                <button class="btn btn-success" type="submit">{{ trans('vela::global.save') }}</button>
                <a href="{{ route('vela.admin.users.index') }}" class="btn btn-secondary">{{ trans('vela::global.cancel') ?: 'Cancel' }}</a>
            </div>
        </form>
    </div>
</div>

{{-- Session info: read-only, three columns, subtle. Shown below the
     main form so it doesn't compete for attention with the editable
     fields. --}}
<div class="card mt-3">
    <div class="card-header">Session info</div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group col-md-3">
                <label>{{ trans('vela::cruds.user.fields.last_login_at') }}</label>
                <input class="form-control" type="text" value="{{ $user->last_login_at ?: '—' }}" readonly>
            </div>
            <div class="form-group col-md-3">
                <label>{{ trans('vela::cruds.user.fields.last_ip') }}</label>
                <input class="form-control" type="text" value="{{ $user->last_ip ?: '—' }}" readonly>
            </div>
            <div class="form-group col-md-6">
                <label>{{ trans('vela::cruds.user.fields.useragent') }}</label>
                <input class="form-control" type="text" value="{{ $user->useragent ?: '—' }}" readonly>
            </div>
        </div>
    </div>
</div>

{{-- Related data: user's articles + comments. Lightweight inline list —
     each item links to its own edit page for the full view. Hidden
     entirely if the user has nothing authored. --}}
@php
    $articles = $user->authorContents ?? collect();
    $comments = $user->userComments  ?? collect();
@endphp
@if($articles->isNotEmpty() || $comments->isNotEmpty())
<div class="card mt-3">
    <div class="card-header">{{ trans('vela::global.relatedData') }}</div>
    <ul class="nav nav-tabs" role="tablist" id="relationship-tabs">
        <li class="nav-item">
            <a class="nav-link active" href="#author_contents" role="tab" data-toggle="tab">
                Articles <span class="badge badge-secondary ml-1">{{ $articles->count() }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user_comments" role="tab" data-toggle="tab">
                Comments <span class="badge badge-secondary ml-1">{{ $comments->count() }}</span>
            </a>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane active" role="tabpanel" id="author_contents">
            @if($articles->isEmpty())
                <p class="text-muted m-3 mb-0">No articles authored.</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach($articles as $a)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <a href="{{ route('vela.admin.contents.edit', $a->id) }}"><strong>{{ $a->title }}</strong></a>
                                <br>
                                <small class="text-muted">
                                    <code>/{{ $a->slug }}</code> ·
                                    <span class="badge badge-{{ $a->status === 'published' ? 'success' : 'secondary' }}">{{ $a->status }}</span>
                                    @if($a->published_at) · {{ \Carbon\Carbon::parse($a->published_at)->format('M j, Y') }}@endif
                                </small>
                            </div>
                            <a href="{{ route('vela.admin.contents.edit', $a->id) }}" class="btn btn-sm btn-info">Edit</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="tab-pane" role="tabpanel" id="user_comments">
            @if($comments->isEmpty())
                <p class="text-muted m-3 mb-0">No comments posted.</p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach($comments as $c)
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <p class="mb-1">{{ \Illuminate\Support\Str::limit(strip_tags($c->comment ?? $c->body ?? ''), 160) }}</p>
                                    <small class="text-muted">
                                        @if($c->content_id && $c->content)
                                            on <a href="{{ route('vela.admin.contents.edit', $c->content_id) }}">{{ $c->content->title }}</a> ·
                                        @endif
                                        {{ $c->created_at?->format('M j, Y · H:i') }}
                                    </small>
                                </div>
                                <a href="{{ route('vela.admin.comments.edit', $c->id) }}" class="btn btn-sm btn-info ml-3">Edit</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endif

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
