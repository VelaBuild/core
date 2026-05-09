@extends('vela::layouts.admin')

@section('breadcrumb', 'New user')

@section('content')
<x-vela::edit-page
    title="New user"
    subtitle="Invite someone to the admin."
    :breadcrumb="[
        ['label' => 'Users', 'url' => route('vela.admin.users.index')],
        ['label' => 'Create'],
    ]"
    :action="route('vela.admin.users.store')"
    method="POST"
    :cancel-url="route('vela.admin.users.index')"
    save-label="Create user"
>
    <x-slot name="main">
        <x-vela::section title="Identity" description="How this person shows up around Vela.">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="required" for="name">{{ trans('vela::cruds.user.fields.name') }}</label>
                    <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', '') }}" required>
                    @if($errors->has('name'))<div class="invalid-feedback">{{ $errors->first('name') }}</div>@endif
                </div>
                <div class="form-group col-md-6">
                    <label class="required" for="email">{{ trans('vela::cruds.user.fields.email') }}</label>
                    <input class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" type="email" name="email" id="email" value="{{ old('email') }}" required>
                    @if($errors->has('email'))<div class="invalid-feedback">{{ $errors->first('email') }}</div>@endif
                </div>
            </div>
        </x-vela::section>

        <x-vela::section title="Access" description="Set the initial password and assign one or more roles.">
            <div class="form-row">
                <div class="form-group col-md-5">
                    <label class="required" for="password">{{ trans('vela::cruds.user.fields.password') }}</label>
                    <div class="input-group">
                        <input class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}" type="password" name="password" id="password" required autocomplete="new-password" data-pw-input>
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" type="button" data-pw-toggle title="{{ __('Show / hide password') }}">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-outline-secondary" type="button" data-pw-generate title="{{ __('Generate a secure password') }}">
                                <i class="fas fa-key"></i> {{ __('Generate') }}
                            </button>
                        </div>
                    </div>
                    @if($errors->has('password'))<div class="invalid-feedback">{{ $errors->first('password') }}</div>@endif
                    <small class="form-text text-muted" data-pw-hint>{{ trans('vela::cruds.user.fields.password_helper') }}</small>
                </div>
                <div class="form-group col-md-7">
                    <label class="required" for="roles">{{ trans('vela::cruds.user.fields.roles') }}</label>
                    <select class="form-control select2 {{ $errors->has('roles') ? 'is-invalid' : '' }}" name="roles[]" id="roles" multiple required>
                        @foreach($roles as $id => $role)
                            <option value="{{ $id }}" {{ in_array($id, old('roles', [])) ? 'selected' : '' }}>{{ $role }}</option>
                        @endforeach
                    </select>
                    @if($errors->has('roles'))<div class="invalid-feedback">{{ $errors->first('roles') }}</div>@endif
                </div>
            </div>
        </x-vela::section>

        <x-vela::section title="Profile" description="Picture and bio shown on the public site. Optional.">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="profile_pic">{{ trans('vela::cruds.user.fields.profile_pic') }}</label>
                    <div class="needsclick dropzone {{ $errors->has('profile_pic') ? 'is-invalid' : '' }}" id="profile_pic-dropzone"></div>
                    @if($errors->has('profile_pic'))<div class="invalid-feedback">{{ $errors->first('profile_pic') }}</div>@endif
                </div>
                <div class="form-group col-md-8">
                    <label for="bio">{{ trans('vela::cruds.user.fields.bio') }}</label>
                    <textarea class="form-control ckeditor {{ $errors->has('bio') ? 'is-invalid' : '' }}" name="bio" id="bio">{!! old('bio') !!}</textarea>
                    @if($errors->has('bio'))<div class="invalid-feedback">{{ $errors->first('bio') }}</div>@endif
                </div>
            </div>
        </x-vela::section>
    </x-slot>

    <x-slot name="side">
        <x-vela::meta-card title="Preferences">
            <label class="vela-switch-row">
                <input type="hidden" name="subscribe_newsletter" value="0">
                <input type="checkbox" name="subscribe_newsletter" value="1" {{ old('subscribe_newsletter', 0) == 1 ? 'checked' : '' }}>
                <span class="vela-switch-label">
                    <strong>Newsletter</strong>
                    <small>Product updates and release notes.</small>
                </span>
            </label>
        </x-vela::meta-card>
    </x-slot>
</x-vela::edit-page>
@endsection

@section('scripts')
<script>
    // Password helpers — toggle visibility + generate a strong random one.
    // Avoids characters that look alike (0/O, 1/l/I) so admins reading the
    // generated password to a user over the phone don't get tripped up.
    (function () {
        var input = document.querySelector('[data-pw-input]');
        if (!input) return;
        var toggle = document.querySelector('[data-pw-toggle]');
        var gen = document.querySelector('[data-pw-generate]');
        var hint = document.querySelector('[data-pw-hint]');

        if (toggle) {
            toggle.addEventListener('click', function () {
                var isPw = input.type === 'password';
                input.type = isPw ? 'text' : 'password';
                toggle.querySelector('i').className = isPw ? 'fas fa-eye-slash' : 'fas fa-eye';
            });
        }

        if (gen) {
            var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*';
            gen.addEventListener('click', function () {
                var out = '';
                var arr = new Uint32Array(20);
                (window.crypto || window.msCrypto).getRandomValues(arr);
                for (var i = 0; i < 20; i++) out += alphabet.charAt(arr[i] % alphabet.length);
                input.value = out;
                input.type = 'text'; // reveal so the admin can copy/note it
                if (toggle) toggle.querySelector('i').className = 'fas fa-eye-slash';
                if (hint) hint.innerHTML = '<i class="fas fa-info-circle"></i> {{ __('Generated — copy or note it before saving; it won’t be shown again.') }}';
                input.focus();
                input.select();
                // Best-effort copy to clipboard.
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(out).catch(function () {});
                }
            });
        }
    })();

    Dropzone.options.profilePicDropzone = {
        url: '{{ route('vela.admin.users.storeMedia') }}',
        maxFilesize: 20,
        acceptedFiles: '.jpeg,.jpg,.png,.gif',
        maxFiles: 1,
        addRemoveLinks: true,
        headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
        params: { size: 20, width: 2000, height: 2000 },
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
        error: function (file, response) {
            var message = $.type(response) === 'string' ? response : response.errors.file
            file.previewElement.classList.add('dz-error')
            var nodes = file.previewElement.querySelectorAll('[data-dz-errormessage]')
            for (var i = 0; i < nodes.length; i++) nodes[i].textContent = message
        }
    }

    $(document).ready(function () {
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
