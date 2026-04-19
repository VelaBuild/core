{{-- AI Chatbot Sidebar — Vela Design System --}}
@php $aiConfigured = app(\VelaBuild\Core\Services\AiProviderManager::class)->hasTextProvider(); @endphp
<div id="ai-chatbot-sidebar" class="ai-chatbot-sidebar vela-ai-chat" style="display:none; position:fixed; right:16px; top:80px; bottom:16px; width:380px; z-index:1060;">
    <div class="vela-ai-chat-head">
        <div class="vela-ai-avatar">V</div>
        <div style="flex: 1;">
            <div class="vela-ai-chat-title">{{ trans('vela::ai.helper_title') }}</div>
            @if($aiConfigured)
                <div class="vela-ai-chat-status">{{ trans('vela::ai.ready') }}</div>
            @else
                <div style="font-size: 11px; color: var(--v-fg-subtle);">{{ trans('vela::ai.not_configured') }}</div>
            @endif
        </div>
        <div style="display:flex; gap:4px;">
            <button class="vela-btn vela-btn-ghost vela-btn-sm vela-btn-icon" id="ai-chat-new" title="{{ trans('vela::ai.new_conversation') }}">
                <i class="fas fa-plus"></i>
            </button>
            <button class="vela-btn vela-btn-ghost vela-btn-sm vela-btn-icon" id="ai-chat-history" title="{{ trans('vela::ai.conversation_history') }}">
                <i class="fas fa-history"></i>
            </button>
            <button class="vela-btn vela-btn-ghost vela-btn-sm vela-btn-icon" id="ai-chat-close" title="{{ trans('vela::global.close') }}">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <div class="vela-ai-messages" id="ai-chat-messages">
        @if($aiConfigured)
        <div class="vela-msg-ai">
            <strong>{{ trans('vela::ai.welcome') }}</strong> {{ trans('vela::ai.welcome_description') }}
            <div class="suggestion">{{ trans('vela::ai.can_create_pages') }}</div>
            <div class="suggestion">{{ trans('vela::ai.can_update_config') }}</div>
            <div class="suggestion">{{ trans('vela::ai.can_generate_images') }}</div>
            <div style="font-size: 11px; color: var(--v-fg-muted); margin-top: 8px;">{{ trans('vela::ai.just_describe') }}</div>
        </div>
        @else
        <div class="vela-msg-ai">
            <strong>{{ trans('vela::ai.not_configured') }}</strong>
            <p style="margin-top: 8px; margin-bottom: 8px;">{{ trans('vela::ai.add_api_key') }}</p>
            <ul style="padding-left: 16px; margin-bottom: 8px;">
                <li>{{ trans('vela::ai.provider_openai') }}</li>
                <li>{{ trans('vela::ai.provider_anthropic') }}</li>
                <li>{{ trans('vela::ai.provider_gemini') }}</li>
            </ul>
            @can('config_edit')
            <a href="{{ route('vela.admin.ai-settings.index') }}" class="vela-btn vela-btn-accent vela-btn-sm" style="margin-top: 4px;">
                <i class="fas fa-cog mr-1"></i> {{ trans('vela::ai.configure_settings') }}
            </a>
            @else
            <small style="color: var(--v-fg-subtle);">{{ trans('vela::ai.ask_admin_configure') }}</small>
            @endcan
        </div>
        @endif
    </div>

    {{-- Undo bar --}}
    <div class="ai-chatbot-undo" id="ai-chat-undo" style="display:none; padding: 8px 12px; background: var(--vela-warn-bg); border-top: 1px solid var(--v-border); display: flex; align-items: center; justify-content: space-between;">
        <span id="ai-chat-undo-text" style="font-size: var(--v-text-sm);"></span>
        <button class="vela-btn vela-btn-sm" id="ai-chat-undo-btn" style="background: var(--vela-warn); color: #fff;">
            <i class="fas fa-undo mr-1"></i> {{ trans('vela::ai.undo') }}
        </button>
    </div>

    <div class="vela-ai-compose">
        <input type="text" id="ai-chat-input" placeholder="{{ $aiConfigured ? trans('vela::ai.type_message') : trans('vela::ai.configure_to_chat') }}" autocomplete="off" {{ $aiConfigured ? '' : 'disabled' }}>
        <button class="vela-btn vela-btn-accent vela-btn-sm vela-btn-icon" id="ai-chat-send" type="button" {{ $aiConfigured ? '' : 'disabled' }}>
            <i class="fas fa-arrow-up"></i>
        </button>
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
