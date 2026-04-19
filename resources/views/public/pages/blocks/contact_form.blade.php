@php
    $settings = $block->settings ?? [];
    $fields   = $settings['fields'] ?? [
        'name'    => ['enabled' => true, 'required' => true],
        'email'   => ['enabled' => true, 'required' => true],
        'phone'   => ['enabled' => true, 'required' => false],
        'subject' => ['enabled' => true, 'required' => false],
        'message' => ['enabled' => true, 'required' => true],
    ];
    $submitLabel    = $settings['submit_label'] ?? trans('vela::global.send_message');
    $successMessage = $settings['success_message'] ?? trans('vela::global.thank_you_message');
@endphp
<div class="block-contact-form">
@if(session('success'))
        <div class="form-success">{{ session('success') }}</div>
@endif
@if($errors->any())
        <div class="form-error">
            <ul style="margin:0;padding-left:1.2em;">
@foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
@endforeach
            </ul>
        </div>
@endif

    <form method="POST" action="{{ route('vela.public.page-form.submit', $page) }}">
        <input type="hidden" name="_token" value="">
        <input type="hidden" name="block_id" value="{{ $block->id }}">

        {{-- Honeypot field --}}
        <div class="honeypot" aria-hidden="true">
            <label for="website_url">{{ trans('vela::global.website') }}</label>
            <input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off">
        </div>

@if(!empty($fields['name']['enabled']))
            <div class="form-group">
                <label for="name">{{ trans('vela::global.contact_name') }}@if(!empty($fields['name']['required'])) *@endif</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}"{{ !empty($fields['name']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['email']['enabled']))
            <div class="form-group">
                <label for="email">{{ trans('vela::global.contact_email') }}@if(!empty($fields['email']['required'])) *@endif</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}"{{ !empty($fields['email']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['phone']['enabled']))
            <div class="form-group">
                <label for="phone">{{ trans('vela::global.contact_phone') }}@if(!empty($fields['phone']['required'])) *@endif</label>
                <input type="tel" name="phone" id="phone" value="{{ old('phone') }}"{{ !empty($fields['phone']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['subject']['enabled']))
            <div class="form-group">
                <label for="subject">{{ trans('vela::global.contact_subject') }}@if(!empty($fields['subject']['required'])) *@endif</label>
                <input type="text" name="subject" id="subject" value="{{ old('subject') }}"{{ !empty($fields['subject']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['message']['enabled']))
            <div class="form-group">
                <label for="message">{{ trans('vela::global.contact_message') }}@if(!empty($fields['message']['required'])) *@endif</label>
                <textarea name="message" id="message" rows="5"{{ !empty($fields['message']['required']) ? ' required' : '' }}>{{ old('message') }}</textarea>
            </div>
@endif

@if(env('TURNSTILE_SITE_KEY'))
            <div class="cf-turnstile" data-sitekey="{{ env('TURNSTILE_SITE_KEY') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif

        <button type="submit">{{ $submitLabel }}</button>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[action*="page-form"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var f = this;
            fetch('/api/csrf-token')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var input = f.querySelector('input[name="_token"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = '_token';
                        f.appendChild(input);
                    }
                    input.value = data.token;
                    f.submit();
                })
                .catch(function() { f.submit(); });
        });
    });
});
</script>
