@php
    $content  = $block->content ?? [];
    $settings = $block->settings ?? [];
    $fields   = $settings['fields'] ?? [
        'name'    => ['enabled' => true, 'required' => true],
        'email'   => ['enabled' => true, 'required' => true],
        'phone'   => ['enabled' => true, 'required' => false],
        'subject' => ['enabled' => true, 'required' => false],
        'message' => ['enabled' => true, 'required' => true],
    ];
    $submitLabel    = ($settings['submit_label'] ?? '') ?: trans('vela::global.send_message');
    $successMessage = $settings['success_message'] ?? trans('vela::global.thank_you_message');

    // stacked is how every contact form looked before there was a choice, so
    // a block saved before then — with no layout at all — still looks that way.
    $layouts = ['stacked', 'two_column', 'split_info', 'split_image', 'card'];
    $layout  = in_array($settings['layout'] ?? '', $layouts, true) ? $settings['layout'] : 'stacked';
    $asideRight = ($settings['aside_position'] ?? 'left') === 'right';

    $info = array_filter([
        'address' => trim((string) ($content['info_address'] ?? '')),
        'phone'   => trim((string) ($content['info_phone'] ?? '')),
        'email'   => trim((string) ($content['info_email'] ?? '')),
        'hours'   => trim((string) ($content['info_hours'] ?? '')),
    ], 'strlen');
    $image = trim((string) ($content['image'] ?? ''));

    // The layout chosen is the layout drawn, even before anything is filled in
    // beside the form. This used to fall back to stacked when a split had
    // nothing to show, and to the person who had just picked a split that
    // looked like the choice had not saved. The dialog says what is missing.

    $isSplit = in_array($layout, ['split_info', 'split_image'], true);
    // The heading sits beside the form in split_info and above it everywhere else.
    $headingInAside = $layout === 'split_info';

    $classes = 'block-contact-form block-contact-form--' . str_replace('_', '-', $layout)
        . ($isSplit && $asideRight ? ' block-contact-form--aside-right' : '');

    $icons = [
        'address' => '<path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
        'phone'   => '<path d="M5 3h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 12l5 2v3a2 2 0 0 1-2 2A15 15 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        'email'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'hours'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    $infoLabels = [
        'address' => trans('vela::global.contact_address'),
        'phone'   => trans('vela::global.contact_phone'),
        'email'   => trans('vela::global.contact_email'),
        'hours'   => trans('vela::global.contact_hours'),
    ];
@endphp
<div class="{{ $classes }}">
@if($layout === 'card')
    <div class="block-contact-form-card">
@endif
@if($isSplit)
    <div class="block-contact-form-grid">
        <div class="block-contact-form-aside">
@if($layout === 'split_image')
@if($image !== '')
            {!! vela_image($image, $content['image_alt'] ?? '', [480, 800, 1200], 'fit', ['class' => 'block-contact-form-image']) !!}
@endif
@else
@if(!empty($content['title']))
            <h2 class="block-contact-form-title">{{ $content['title'] }}</h2>
@endif
@if(!empty($content['intro']))
            <p class="block-contact-form-intro">{{ $content['intro'] }}</p>
@endif
@if($info)
            <ul class="block-contact-form-info">
@foreach($info as $key => $value)
                <li class="block-contact-form-info-item block-contact-form-info-{{ $key }}">
                    <svg class="block-contact-form-info-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$key] !!}</svg>
                    <span>
                        <span class="block-contact-form-info-label">{{ $infoLabels[$key] }}</span>
@if($key === 'phone')
                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $value) }}">{{ $value }}</a>
@elseif($key === 'email')
                        <a href="mailto:{{ $value }}">{{ $value }}</a>
@else
                        <span class="block-contact-form-info-value">{!! nl2br(e($value)) !!}</span>
@endif
                    </span>
                </li>
@endforeach
            </ul>
@endif
@endif
        </div>
        <div class="block-contact-form-main">
@endif
@if(!$headingInAside)
@if(!empty($content['title']))
        <h2 class="block-contact-form-title">{{ $content['title'] }}</h2>
@endif
@if(!empty($content['intro']))
        <p class="block-contact-form-intro">{{ $content['intro'] }}</p>
@endif
@endif
@if(session('success'))
        <div class="form-success">{{ session('success') }}</div>
@endif
@if(isset($errors) && $errors->any())
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

        <div class="block-contact-form-fields">
@if(!empty($fields['name']['enabled']))
            <div class="form-group form-group-name">
                <label for="name">{{ trans('vela::global.contact_name') }}@if(!empty($fields['name']['required'])) *@endif</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}"{{ !empty($fields['name']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['email']['enabled']))
            <div class="form-group form-group-email">
                <label for="email">{{ trans('vela::global.contact_email') }}@if(!empty($fields['email']['required'])) *@endif</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}"{{ !empty($fields['email']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['phone']['enabled']))
            <div class="form-group form-group-phone">
                <label for="phone">{{ trans('vela::global.contact_phone') }}@if(!empty($fields['phone']['required'])) *@endif</label>
                <input type="tel" name="phone" id="phone" value="{{ old('phone') }}"{{ !empty($fields['phone']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['subject']['enabled']))
            <div class="form-group form-group-subject">
                <label for="subject">{{ trans('vela::global.contact_subject') }}@if(!empty($fields['subject']['required'])) *@endif</label>
                <input type="text" name="subject" id="subject" value="{{ old('subject') }}"{{ !empty($fields['subject']['required']) ? ' required' : '' }}>
            </div>
@endif

@if(!empty($fields['message']['enabled']))
            <div class="form-group form-group-message">
                <label for="message">{{ trans('vela::global.contact_message') }}@if(!empty($fields['message']['required'])) *@endif</label>
                <textarea name="message" id="message" rows="5"{{ !empty($fields['message']['required']) ? ' required' : '' }}>{{ old('message') }}</textarea>
            </div>
@endif
        </div>

@if(env('TURNSTILE_SITE_KEY'))
            <div class="cf-turnstile" data-sitekey="{{ env('TURNSTILE_SITE_KEY') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif

        <button type="submit">{{ $submitLabel }}</button>
    </form>
@if($isSplit)
        </div>
    </div>
@endif
@if($layout === 'card')
    </div>
@endif
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
