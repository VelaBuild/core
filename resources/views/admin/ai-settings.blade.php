@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        <i class="fas fa-robot"></i> {{ trans('vela::ai.settings_title') }}
    </div>
    <div class="card-body">
        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif

        <form method="POST" action="{{ route('vela.admin.ai-settings.update') }}">
            @csrf

            <h5 class="mb-3">{{ trans('vela::ai.api_keys') }}</h5>
            <p class="text-muted small">{{ trans('vela::ai.api_keys_description') }}</p>

            @foreach(['openai' => trans('vela::ai.openai'), 'anthropic' => trans('vela::ai.provider_anthropic'), 'gemini' => trans('vela::ai.provider_gemini')] as $provider => $label)
                <div class="form-group row">
                    <label class="col-md-3 col-form-label">{{ $label }}</label>
                    <div class="col-md-9">
                        @if($status['providers'][$provider]['env_locked'])
                            <input type="text" class="form-control" value="{{ trans('vela::ai.set_via_env') }}" disabled>
                            <small class="text-success"><i class="fas fa-lock"></i> {{ trans('vela::ai.configured_in_env') }}</small>
                        @else
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       name="{{ $provider }}_api_key"
                                       value="{{ $status['providers'][$provider]['has_key'] ? 'unchanged' : '' }}"
                                       placeholder="{{ trans('vela::ai.enter_api_key') }}"
                                       onfocus="if(this.value==='unchanged'){this.value='';this.type='text'}"
                                       onblur="if(this.value===''){this.value='unchanged';this.type='password'}">
                                @if($status['providers'][$provider]['has_key'])
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').querySelector('input').value='';this.closest('.input-group').querySelector('input').type='text';this.closest('.input-group').querySelector('input').placeholder='{{ trans('vela::ai.key_cleared') }}'">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                @endif
                            </div>
                            @if($status['providers'][$provider]['has_key'])
                                <small class="text-success"><i class="fas fa-check"></i> {{ trans('vela::ai.key_configured') }} ({{ $status['providers'][$provider]['masked_key'] }})</small>
                            @else
                                <small class="text-muted">{{ trans('vela::ai.no_key_set') }}</small>
                            @endif
                        @endif

                        {{-- Anthropic only. Without it an identity-linked key is
                             refused on every call, including the one that would
                             have reported the key as working — so the provider
                             reads as dead rather than as needing one more field. --}}
                        @if($provider === 'anthropic')
                            <div class="mt-2">
                                @if($status['anthropic_workspace_id_locked'])
                                    <input type="text" class="form-control form-control-sm"
                                           value="{{ $status['anthropic_workspace_id'] }} (set via .env)" disabled>
                                @else
                                    <input type="text"
                                           class="form-control form-control-sm {{ $errors->has('anthropic_workspace_id') ? 'is-invalid' : '' }}"
                                           name="anthropic_workspace_id"
                                           value="{{ old('anthropic_workspace_id', $status['anthropic_workspace_id']) }}"
                                           placeholder="{{ trans('vela::ai.workspace_id_placeholder') }}"
                                           autocomplete="off" spellcheck="false">
                                    @if($errors->has('anthropic_workspace_id'))
                                        <div class="invalid-feedback d-block">{{ $errors->first('anthropic_workspace_id') }}</div>
                                    @endif
                                    <small class="text-muted">{{ trans('vela::ai.workspace_id_help') }}</small>
                                @endif
                            </div>
                        @endif

                        {{-- The model, beside the key it is used with. This page
                             let you pick a provider and never a model, which is
                             the larger of the two decisions: on one design an
                             old model wrote a fifteenth of the styling a current
                             one did.

                             A menu, because the person this admin is built for
                             does not know model ids and should not have to — but
                             with "Other", because a list baked into a release
                             cannot name the model that comes out after it, and
                             without that escape this field would go stale and
                             have to be edited in .env again. A value already set
                             that is not on the list is added to it, so a choice
                             made before this version is never silently lost. --}}
                        @php
                            $model = $status['providers'][$provider]['model'] ?? '';
                            $chosen = old($provider . '_model', $model);
                            $shipped = $status['providers'][$provider]['model_default'] ?? '';
                            $options = $status['providers'][$provider]['model_suggestions'] ?? [];
                            if ($chosen !== '' && !in_array($chosen, $options, true)) {
                                array_unshift($options, $chosen);
                            }
                            $inUse = $chosen !== '' ? $chosen : $shipped;
                            $concern = $inUse !== '' ? app(\VelaBuild\Core\Services\DesignBuilderService::class)->modelConcern($inUse) : null;
                        @endphp
                        <div class="mt-2">
                            @if($status['providers'][$provider]['model_locked'])
                                <input type="text" class="form-control form-control-sm" value="{{ $inUse }} (set via .env)" disabled>
                            @else
                                <select name="{{ $provider }}_model" class="form-control form-control-sm vela-model-select"
                                        data-other="#{{ $provider }}-model-other">
                                    <option value="">{{ trans('vela::ai.model_default', ['model' => $shipped]) }}</option>
                                    @foreach($options as $option)
                                        <option value="{{ $option }}" {{ $chosen === $option ? 'selected' : '' }}>{{ $option }}</option>
                                    @endforeach
                                    <option value="__other">{{ trans('vela::ai.model_other') }}</option>
                                </select>
                                {{-- Shown by the select above. Left visible where
                                     scripts do not run, so the escape hatch is
                                     never the thing that breaks. --}}
                                <input type="text" id="{{ $provider }}-model-other" name="{{ $provider }}_model_other"
                                       class="form-control form-control-sm mt-1 vela-model-other {{ $errors->has($provider . '_model') ? 'is-invalid' : '' }}"
                                       value="{{ old($provider . '_model_other') }}"
                                       placeholder="{{ trans('vela::ai.model_other_placeholder') }}"
                                       autocomplete="off" spellcheck="false">
                                @if($errors->has($provider . '_model'))
                                    <div class="invalid-feedback d-block">{{ $errors->first($provider . '_model') }}</div>
                                @endif
                            @endif
                            @if($concern)
                                <small class="text-warning d-block mt-1"><i class="fas fa-exclamation-triangle"></i> {{ $inUse }} — {{ $concern }}</small>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            <hr>
            <h5 class="mb-3">{{ trans('vela::ai.provider_selection') }}</h5>
            <p class="text-muted small">{{ trans('vela::ai.provider_selection_desc') }}</p>

            <div class="form-group row">
                <label class="col-md-3 col-form-label">{{ trans('vela::ai.chat_text') }}</label>
                <div class="col-md-9">
                    @if($status['chat_provider_locked'])
                        <input type="text" class="form-control" value="{{ ucfirst($status['chat_provider']) }} (set via .env)" disabled>
                    @else
                        <select name="chat_provider" class="form-control">
                            <option value="auto" {{ $status['chat_provider'] === 'auto' ? 'selected' : '' }}>{{ trans('vela::ai.auto_first_available') }}</option>
                            <option value="openai" {{ $status['chat_provider'] === 'openai' ? 'selected' : '' }}>{{ trans('vela::ai.openai_gpt') }}</option>
                            <option value="anthropic" {{ $status['chat_provider'] === 'anthropic' ? 'selected' : '' }}>{{ trans('vela::ai.anthropic_claude') }}</option>
                            <option value="gemini" {{ $status['chat_provider'] === 'gemini' ? 'selected' : '' }}>{{ trans('vela::ai.google_gemini') }}</option>
                        </select>
                    @endif
                </div>
            </div>

            <div class="form-group row">
                <label class="col-md-3 col-form-label">{{ trans('vela::ai.image_generation') }}</label>
                <div class="col-md-9">
                    @if($status['image_provider_locked'])
                        <input type="text" class="form-control" value="{{ ucfirst($status['image_provider']) }} (set via .env)" disabled>
                    @else
                        <select name="image_provider" class="form-control">
                            <option value="auto" {{ $status['image_provider'] === 'auto' ? 'selected' : '' }}>{{ trans('vela::ai.auto_first_available') }}</option>
                            <option value="gemini" {{ $status['image_provider'] === 'gemini' ? 'selected' : '' }}>{{ trans('vela::ai.google_gemini') }}</option>
                            <option value="openai" {{ $status['image_provider'] === 'openai' ? 'selected' : '' }}>{{ trans('vela::ai.openai_image') }}</option>
                        </select>
                    @endif
                </div>
            </div>

            <div class="form-group row">
                <label class="col-md-3 col-form-label">OpenAI image model</label>
                <div class="col-md-9">
                    @if($status['openai_image_model_locked'])
                        <input type="text" class="form-control" value="{{ $status['openai_image_model'] }} (set via .env)" disabled>
                    @else
                        <select name="openai_image_model" class="form-control">
                            <option value="gpt-image-1"   {{ $status['openai_image_model'] === 'gpt-image-1' ? 'selected' : '' }}>gpt-image-1</option>
                            <option value="gpt-image-1.5" {{ $status['openai_image_model'] === 'gpt-image-1.5' ? 'selected' : '' }}>gpt-image-1.5 (recommended)</option>
                            <option value="gpt-image-2"   {{ $status['openai_image_model'] === 'gpt-image-2' ? 'selected' : '' }}>gpt-image-2 (latest)</option>
                        </select>
                        <small class="text-muted d-block mt-1">Only used when the image provider above is OpenAI. <code>gpt-image-1.5</code> is the safe default; <code>gpt-image-2</code> supports larger sizes and arbitrary aspect ratios; <code>gpt-image-1</code> is the original release.</small>
                    @endif
                </div>
            </div>

            <hr>

            <h5 class="mb-3">Web Search</h5>
            <p class="text-muted small">Let the AI ground responses in fresh web results before answering. Provider-native search costs are billed by your AI provider.</p>

            <div class="form-group row">
                <label class="col-md-3 col-form-label">Native search</label>
                <div class="col-md-9">
                    <div class="form-check">
                        <input type="hidden" name="native_search" value="0">
                        <input class="form-check-input" type="checkbox" id="native_search" name="native_search" value="1"
                               {{ ($status['native_search'] ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="native_search">
                            Enable Gemini <code>google_search</code> + Claude <code>web_search_20250305</code> on chat requests.
                        </label>
                    </div>
                    <small class="text-muted d-block mt-1">OpenAI Chat Completions has no native equivalent — for OpenAI, the chatbot's <code>web_search</code> tool will use <code>BRAVE_SEARCH_API_KEY</code> / <code>TAVILY_API_KEY</code> / <code>SERPER_API_KEY</code> if set in <code>.env</code>.</small>
                </div>
            </div>

            <hr>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> {{ trans('vela::global.save_settings') }}
                </button>
                <a href="{{ route('vela.admin.home') }}" class="btn btn-secondary ml-2">{{ trans('vela::global.cancel') }}</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// "Other" reveals the box beside it; every other choice hides it. Hidden means
// hidden, not disabled — the server reads the text box only when the menu says
// to, so a stale value left in it cannot leak into the save.
document.querySelectorAll('.vela-model-select').forEach(function (select) {
    var other = document.querySelector(select.dataset.other);
    if (!other) { return; }

    function sync() {
        other.style.display = select.value === '__other' ? '' : 'none';
    }

    select.addEventListener('change', function () {
        sync();
        if (select.value === '__other') { other.focus(); }
    });
    sync();
});
</script>
@endpush

@endsection
