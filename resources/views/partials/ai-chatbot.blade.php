{{-- AI Chatbot Sidebar --}}
@php $aiConfigured = app(\VelaBuild\Core\Services\AiProviderManager::class)->hasTextProvider(); @endphp
<div id="ai-chatbot-sidebar" class="ai-chatbot-sidebar" style="display:none;">
    <div class="ai-chatbot-header">
        <h6 class="mb-0"><i class="fas fa-robot mr-2"></i>{{ trans('vela::ai.helper_title') }}</h6>
        <div>
            <button class="btn btn-sm btn-link text-white" id="ai-chat-new" title="{{ trans('vela::ai.new_conversation') }}">
                <i class="fas fa-plus"></i>
            </button>
            <button class="btn btn-sm btn-link text-white" id="ai-chat-history" title="{{ trans('vela::ai.conversation_history') }}">
                <i class="fas fa-history"></i>
            </button>
            <button class="btn btn-sm btn-link text-white" id="ai-chat-close" title="{{ trans('vela::global.close') }}">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <div class="ai-chatbot-messages" id="ai-chat-messages">
        @if($aiConfigured)
        <div class="ai-chat-message ai-chat-assistant">
            <div class="ai-chat-bubble">
                <strong>{{ trans('vela::ai.welcome') }}</strong> {{ trans('vela::ai.welcome_description') }}
                <ul class="mt-2 mb-0 pl-3">
                    <li>{{ trans('vela::ai.can_create_pages') }}</li>
                    <li>{{ trans('vela::ai.can_update_config') }}</li>
                    <li>{{ trans('vela::ai.can_customize_colors') }}</li>
                    <li>{{ trans('vela::ai.can_generate_images') }}</li>
                    <li>{{ trans('vela::ai.can_edit_templates') }}</li>
                    <li>{{ trans('vela::ai.can_manage_categories') }}</li>
                </ul>
                <small class="text-muted d-block mt-2">{{ trans('vela::ai.just_describe') }}</small>
            </div>
        </div>
        @else
        <div class="ai-chat-message ai-chat-assistant">
            <div class="ai-chat-bubble">
                <strong>{{ trans('vela::ai.not_configured') }}</strong>
                <p class="mt-2 mb-2">{{ trans('vela::ai.add_api_key') }}</p>
                <ul class="pl-3 mb-2">
                    <li>{{ trans('vela::ai.provider_openai') }}</li>
                    <li>{{ trans('vela::ai.provider_anthropic') }}</li>
                    <li>{{ trans('vela::ai.provider_gemini') }}</li>
                </ul>
                @can('config_edit')
                <a href="{{ route('vela.admin.ai-settings.index') }}" class="btn btn-sm btn-primary mt-1">
                    <i class="fas fa-cog"></i> {{ trans('vela::ai.configure_settings') }}
                </a>
                @else
                <small class="text-muted">{{ trans('vela::ai.ask_admin_configure') }}</small>
                @endcan
            </div>
        </div>
        @endif
    </div>

    {{-- Undo bar --}}
    <div class="ai-chatbot-undo" id="ai-chat-undo" style="display:none;">
        <span id="ai-chat-undo-text"></span>
        <button class="btn btn-sm btn-warning" id="ai-chat-undo-btn">
            <i class="fas fa-undo"></i> {{ trans('vela::ai.undo') }}
        </button>
    </div>

    <div class="ai-chatbot-input">
        <div class="input-group">
            <input type="text" class="form-control" id="ai-chat-input" placeholder="{{ $aiConfigured ? trans('vela::ai.type_message') : trans('vela::ai.configure_to_chat') }}" autocomplete="off" {{ $aiConfigured ? '' : 'disabled' }}>
            <div class="input-group-append">
                <button class="btn btn-primary" id="ai-chat-send" type="button" {{ $aiConfigured ? '' : 'disabled' }}>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window._aiChatConfig = {
        routes: {
            message: '{{ route("vela.admin.ai-chat.message") }}',
            poll: '{{ route("vela.admin.ai-chat.poll", "__ID__") }}',
            undo: '{{ route("vela.admin.ai-chat.undo", "__ID__") }}',
            conversations: '{{ route("vela.admin.ai-chat.conversations") }}',
            history: '{{ route("vela.admin.ai-chat.history", "__ID__") }}'
        },
        csrfToken: '{{ csrf_token() }}',
        configured: {{ $aiConfigured ? 'true' : 'false' }}
    };
</script>
