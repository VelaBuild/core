@extends('vela::layouts.admin')

@section('breadcrumb', $user->name . ' — Edit')

@section('content')
<x-vela::edit-page
    :title="$user->name"
    :subtitle="$user->email"
    :breadcrumb="[
        ['label' => 'Users', 'url' => route('vela.admin.users.index')],
        ['label' => 'Edit'],
    ]"
    :avatar="$user->profile_pic?->thumbnail ?? $user->profile_pic?->url"
    :avatar-fallback="mb_substr($user->name, 0, 1)"
    :action="route('vela.admin.users.update', $user->id)"
    method="PUT"
    :cancel-url="route('vela.admin.users.index')"
>
    <x-slot name="main">
        <x-vela::section title="Identity" description="How this person shows up around Vela.">
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
        </x-vela::section>

        <x-vela::section title="Access" description="Roles decide what this user can see and do.">
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
        </x-vela::section>

        <x-vela::section title="Profile" description="Picture + bio shown on the public site.">
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
        </x-vela::section>
    </x-slot>

    <x-slot name="side">
        <x-vela::meta-card title="Preferences">
            <label class="vela-switch-row">
                <input type="hidden" name="subscribe_newsletter" value="0">
                <input type="checkbox" name="subscribe_newsletter" value="1" {{ $user->subscribe_newsletter ? 'checked' : '' }}>
                <span class="vela-switch-label">
                    <strong>Newsletter</strong>
                    <small>Product updates and release notes.</small>
                </span>
            </label>
        </x-vela::meta-card>

        <x-vela::meta-card title="Session" :status="$user->last_login_at ? true : false" :body-padding="false">
            <dl class="vela-meta-list">
                <dt>Last sign-in</dt>
                <dd>{{ $user->last_login_at ?: 'Never' }}</dd>

                <dt>IP address</dt>
                <dd><code>{{ $user->last_ip ?: '—' }}</code></dd>

                <dt>User agent</dt>
                <dd class="small-text">{{ $user->useragent ?: '—' }}</dd>
            </dl>
        </x-vela::meta-card>

        @php
            $articles = $user->authorContents ?? collect();
            $comments = $user->userComments ?? collect();
        @endphp
        @if($articles->isNotEmpty() || $comments->isNotEmpty())
            <x-vela::meta-card title="Activity" :body-padding="false">
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
                                    @if($a->published_at)<span>· {{ \Carbon\Carbon::parse($a->published_at)->format('M j, Y') }}</span>@endif
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
            </x-vela::meta-card>
        @endif
    </x-slot>
</x-vela::edit-page>

<script>
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.vela-mini-tabs [data-tab]');
    if (!btn) return;
    var container = btn.closest('.vela-meta-card');
    var tab = btn.dataset.tab;
    container.querySelectorAll('.vela-mini-tabs button').forEach(function(b) { b.classList.toggle('is-active', b === btn); });
    container.querySelectorAll('.vela-mini-tab-body').forEach(function(body) {
        if (body.dataset.pane === tab) body.setAttribute('data-active', ''); else body.removeAttribute('data-active');
    });
});
</script>
@endsection

@section('scripts')
<script>
Dropzone.options.profilePicDropzone = {
    url: '{{ route('vela.admin.users.storeMedia') }}',
    maxFilesize: 10,
    acceptedFiles: '.jpeg,.jpg,.png,.gif',
    addRemoveLinks: true,
    headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
    params: { size: 10 },
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
        var message = $.type(response) === 'string' ? response : response.errors.file
        file.previewElement.classList.add('dz-error')
        var _ref = file.previewElement.querySelectorAll('[data-dz-errormessage]')
        for (var _i = 0; _i < _ref.length; _i++) _ref[_i].textContent = message
    }
}

$(document).ready(function () {
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    function SimpleUploadAdapter(editor) {
        editor.plugins.get('FileRepository').createUploadAdapter = function(loader) {
            return {
                upload: function() {
                    return loader.file.then(function (file) {
                        return new Promise(function(resolve, reject) {
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', '{{ route('vela.admin.users.storeCKEditorImages') }}', true);
                            xhr.setRequestHeader('x-csrf-token', window._token);
                            xhr.setRequestHeader('Accept', 'application/json');
                            xhr.responseType = 'json';
                            xhr.addEventListener('error', function() { reject("Upload failed") });
                            xhr.addEventListener('abort', function() { reject() });
                            xhr.addEventListener('load', function() {
                                var response = xhr.response;
                                if (!response || xhr.status !== 201) return reject("Upload failed");
                                $('form').append('<input type="hidden" name="ck-media[]" value="' + response.id + '">');
                                resolve({ default: response.url });
                            });
                            var data = new FormData();
                            data.append('upload', file);
                            xhr.send(data);
                        });
                    })
                }
            };
        }
    }

    document.querySelectorAll('.ckeditor').forEach(function(el) {
        ClassicEditor.create(el, { extraPlugins: [SimpleUploadAdapter] });
    });
});
</script>
@endsection
