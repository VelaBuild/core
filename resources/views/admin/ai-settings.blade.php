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
                            <option value="openai" {{ $status['image_provider'] === 'openai' ? 'selected' : '' }}>{{ trans('vela::ai.openai_dalle') }}</option>
                        </select>
                    @endif
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

@endsection
