@extends('vela::layouts.admin')

@section('breadcrumb', $user->name . ' — Edit')

@section('content')
<div class="vela-edit-page">

    {{-- Page head: breadcrumb + title + avatar + action buttons --}}
    <header class="vela-page-head">
        <div class="vela-page-head-left">
            <div class="vela-breadcrumb">
                <a href="{{ route('vela.admin.users.index') }}">Users</a>
                <span class="sep">/</span>
                <span class="cur">Edit</span>
            </div>
            <div class="vela-page-title-row">
                @if($user->profile_pic)
                    <img class="vela-page-avatar" src="{{ $user->profile_pic->thumbnail ?? $user->profile_pic->url }}" alt="">
                @else
                    <div class="vela-page-avatar-fallback">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</div>
                @endif
                <div>
                    <h1>{{ $user->name }}</h1>
                    <p class="vela-page-sub">{{ $user->email }}</p>
                </div>
            </div>
        </div>
        <div class="vela-page-actions">
            <a href="{{ route('vela.admin.users.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" form="user-form" class="btn btn-success">Save changes</button>
        </div>
    </header>

    <form id="user-form" method="POST" action="{{ route('vela.admin.users.update', [$user->id]) }}" enctype="multipart/form-data">
        @method('PUT')
        @csrf

        <div class="vela-edit-grid">

            {{-- Left column: sectioned form --}}
            <div class="vela-edit-main">

                <section class="vela-section">
                    <div class="vela-section-head">
                        <h2>Identity</h2>
                        <p>How this person shows up around Vela.</p>
                    </div>
                    <div class="vela-section-body">
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
                    </div>
                </section>

                <section class="vela-section">
                    <div class="vela-section-head">
                        <h2>Access</h2>
                        <p>Roles decide what this user can see and do.</p>
                    </div>
                    <div class="vela-section-body">
                        <div class="form-row">
                            <div class="form-group col-md-5">
                                <label for="password">{{ trans('vela::cruds.user.fields.password') }}</label>
                                <input class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}" type="password" name="password" id="password" placeholder="Leave blank to keep current">
                                @if($errors->has('password'))<div class="invalid-feedback">{{ $errors->first('password') }}</div>@endif
                                <small class="form-text text-muted">{{ trans('vela::cruds.user.fields.password_helper') }}</small>
                            </div>
                            <div class="form-group col-md-7">
                                <label class="required" for="roles">{{ trans('vela::cruds.user.fields.roles') }}</label>
                                <select class="form-control select2 {{ $errors->has('roles') ? 'is-invalid' : '' }}" name="roles[]" id="roles" multiple required>
                                    @foreach($roles as $id => $role)
                                        <option value="{{ $id }}" {{ (in_array($id, old('roles', [])) || $user->roles->contains($id)) ? 'selected' : '' }}>{{ $role }}</option>
                                    @endforeach
                                </select>
                                @if($errors->has('roles'))<div class="invalid-feedback">{{ $errors->first('roles') }}</div>@endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="vela-section">
                    <div class="vela-section-head">
                        <h2>Profile</h2>
                        <p>Picture + bio shown on the public site.</p>
                    </div>
                    <div class="vela-section-body">
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
                    </div>
                </section>

                <section class="vela-section">
                    <div class="vela-section-head">
                        <h2>Preferences</h2>
                    </div>
                    <div class="vela-section-body">
                        <label class="vela-switch-row">
                            <input type="hidden" name="subscribe_newsletter" value="0">
                            <input type="checkbox" name="subscribe_newsletter" value="1" {{ $user->subscribe_newsletter ? 'checked' : '' }}>
                            <span class="vela-switch-label">
                                <strong>Subscribe to newsletter</strong>
                                <small>Product updates, release notes, and the occasional deep dive.</small>
                            </span>
                        </label>
                    </div>
                </section>

            </div>

            {{-- Right column: meta sidebar (read-only audit + activity) --}}
            <aside class="vela-edit-side">

                <section class="vela-meta-card">
                    <div class="vela-meta-head">
                        <h3>Session</h3>
                        <span class="dot {{ $user->last_login_at ? 'on' : '' }}" title="{{ $user->last_login_at ? 'Active' : 'Never signed in' }}"></span>
                    </div>
                    <dl class="vela-meta-list">
                        <dt>Last sign-in</dt>
                        <dd>{{ $user->last_login_at ?: 'Never' }}</dd>

                        <dt>IP address</dt>
                        <dd><code>{{ $user->last_ip ?: '—' }}</code></dd>

                        <dt>User agent</dt>
                        <dd class="small-text">{{ $user->useragent ?: '—' }}</dd>
                    </dl>
                </section>

                @php
                    $articles = $user->authorContents ?? collect();
                    $comments = $user->userComments  ?? collect();
                    $hasActivity = $articles->isNotEmpty() || $comments->isNotEmpty();
                @endphp

                @if($hasActivity)
                    <section class="vela-meta-card">
                        <div class="vela-meta-head">
                            <h3>Activity</h3>
                        </div>

                        <div class="vela-mini-tabs" role="tablist">
                            <button type="button" class="is-active" data-tab="articles">Articles <span>{{ $articles->count() }}</span></button>
                            <button type="button" data-tab="comments">Comments <span>{{ $comments->count() }}</span></button>
                        </div>

                        <div class="vela-mini-tab-body" data-pane="articles" data-active>
                            @forelse($articles as $a)
                                <a class="vela-activity-item" href="{{ route('vela.admin.contents.edit', $a->id) }}">
                                    <div class="vela-activity-main">
                                        <strong>{{ $a->title }}</strong>
                                        <div class="vela-activity-meta">
                                            <span class="badge badge-{{ $a->status === 'published' ? 'success' : 'secondary' }}">{{ $a->status }}</span>
                                            @if($a->published_at)
                                                <span>· {{ \Carbon\Carbon::parse($a->published_at)->format('M j, Y') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="vela-activity-arr">→</span>
                                </a>
                            @empty
                                <p class="vela-activity-empty">Nothing authored yet.</p>
                            @endforelse
                        </div>

                        <div class="vela-mini-tab-body" data-pane="comments">
                            @forelse($comments as $c)
                                <a class="vela-activity-item" href="{{ route('vela.admin.comments.edit', $c->id) }}">
                                    <div class="vela-activity-main">
                                        <p>{{ \Illuminate\Support\Str::limit(strip_tags($c->comment ?? $c->body ?? ''), 100) }}</p>
                                        <div class="vela-activity-meta">
                                            @if($c->content_id && $c->content)on <em>{{ $c->content->title }}</em> ·@endif
                                            {{ $c->created_at?->format('M j, Y') }}
                                        </div>
                                    </div>
                                    <span class="vela-activity-arr">→</span>
                                </a>
                            @empty
                                <p class="vela-activity-empty">No comments yet.</p>
                            @endforelse
                        </div>
                    </section>
                @endif

            </aside>
        </div>
    </form>

</div>

<script>
// Mini-tab switcher for the activity card. Pure JS — no dependency on
// Bootstrap's tab machinery.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.vela-mini-tabs [data-tab]');
    if (!btn) return;
    var container = btn.closest('.vela-meta-card');
    var tab = btn.dataset.tab;
    container.querySelectorAll('.vela-mini-tabs button').forEach(function(b) {
        b.classList.toggle('is-active', b === btn);
    });
    container.querySelectorAll('.vela-mini-tab-body').forEach(function(body) {
        if (body.dataset.pane === tab) body.setAttribute('data-active', '');
        else body.removeAttribute('data-active');
    });
});
</script>
@endsection

@section('scripts')
<script>
    Dropzone.options.profilePicDropzone = {
    url: '{{ route('vela.admin.users.storeMedia') }}',
    maxFilesize: 10, // MB
    acceptedFiles: '.jpeg,.jpg,.png,.gif',
    addRemoveLinks: true,
    headers: {
      'X-CSRF-TOKEN': "{{ csrf_token() }}"
    },
    params: {
      size: 10
    },
    success: function (file, response) {
      $('form').find('input[name="profile_pic"]').remove()
      $('form').append('<input type="hidden" name="profile_pic" value="' + response.name + '">')
    },
    removedfile: function (file) {
      file.previewElement.remove()
      if (file.status !== 'error') {
        $('form').find('input[name="profile_pic"]').remove()
        $('form').append('<input type="hidden" name="profile_pic" value="">')
      }
    },
    init: function () {
@if(isset($user) && $user->profile_pic)
      var file = {!! json_encode($user->profile_pic) !!}
          this.options.addedfile.call(this, file)
      this.options.thumbnail.call(this, file, file.url)
      file.previewElement.classList.add('dz-complete')
      $('form').append('<input type="hidden" name="profile_pic" value="' + file.file_name + '">')
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
    var uploaded_images = []

    $(document).ready(function () {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
    })


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
                                            return reject(response && response.message ? `${genericErrorText}\nError: ${response.message}` : genericErrorText);
                                        }

                                        $('form').append('<input type="hidden" name="ck-media[]" value="' + response.id + '">');

                                        resolve({ default: response.url });
                                    });

                                    if (xhr.upload) {
                                        xhr.upload.addEventListener('progress', function(e) {
                                            if (e.lengthComputable) {
                                                loader.uploadTotal = e.total;
                                                loader.uploadedBytes = e.loaded;
                                            }
                                        });
                                    }

                                    // Send request
                                    var data = new FormData();
                                    data.append('upload', file);
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
