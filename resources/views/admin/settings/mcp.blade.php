@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head')

<div class="card">
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form action="{{ route('vela.admin.settings.updateGroup', 'mcp') }}" method="POST" id="mcp-form">
            @csrf

            <div class="form-group">
                <label class="d-block mb-2"><strong>{{ __('vela::mcp.title') }}</strong></label>
                <small class="form-text text-muted mb-3 d-block">{{ __('vela::mcp.intro') }}</small>

                @if($status['enabled_locked'])
                    <div class="alert alert-info py-2 px-3 mb-3">
                        <i class="fas fa-lock mr-1"></i> {{ __('vela::mcp.enabled_via_env') }}
                    </div>
                @else
                    <div class="custom-control custom-switch mb-3">
                        <input type="hidden" name="mcp_enabled" value="0">
                        <input type="checkbox" class="custom-control-input" id="mcp-enabled" name="mcp_enabled" value="1"
                            {{ $status['enabled'] ? 'checked' : '' }}>
                        <label class="custom-control-label" for="mcp-enabled">
                            {{ __('vela::mcp.enable') }}
                        </label>
                    </div>
                @endif
            </div>

            <div id="mcp-options" style="{{ !$status['enabled'] ? 'display:none' : '' }}">
                <div class="form-group">
                    <label>{{ __('vela::mcp.api_key') }}</label>

                    @if($status['api_key_locked'])
                        <input type="text" class="form-control" value="{{ __('vela::mcp.set_via_env') }}" disabled>
                        <small class="text-success"><i class="fas fa-lock"></i> {{ __('vela::mcp.configured_in_env') }}</small>
                    @else
                        <div class="input-group">
                            <input type="password"
                                   class="form-control"
                                   name="mcp_api_key"
                                   id="mcp_api_key"
                                   value="{{ $status['has_api_key'] ? 'unchanged' : '' }}"
                                   placeholder="{{ __('vela::mcp.enter_api_key') }}"
                                   onfocus="if(this.value==='unchanged'){this.value='';this.type='text'}"
                                   onblur="if(this.value===''){this.value='unchanged';this.type='password'}">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" id="mcp-generate-key" title="{{ __('vela::mcp.generate_key') }}">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="mcp-toggle-key" title="{{ __('vela::mcp.show_key') }}">
                                    <i class="fas fa-eye"></i>
                                </button>
                                @if($status['has_api_key'])
                                    <button type="button" class="btn btn-outline-danger" id="mcp-clear-key">
                                        <i class="fas fa-times"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                        @if($status['has_api_key'])
                            <small class="text-success"><i class="fas fa-check"></i> {{ __('vela::mcp.key_configured') }} ({{ $status['masked_key'] }})</small>
                        @else
                            <small class="text-muted">{{ __('vela::mcp.no_key_set') }}</small>
                        @endif
                    @endif
                </div>

                <div class="alert alert-light border mt-3">
                    <h6 class="mb-2"><i class="fas fa-code mr-1"></i> {{ __('vela::mcp.endpoint_title') }}</h6>
                    <p class="text-muted small mb-2">{{ __('vela::mcp.endpoint_desc') }}</p>
                    <code class="d-block mb-1">GET {{ url('/api/mcp') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/posts') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/posts/{slug}') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/pages') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/pages/{slug}') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/categories') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/settings') }}</code>
                    <code class="d-block mb-1">GET {{ url('/api/mcp/settings/{group}') }}</code>
                    <code class="d-block mb-1">PUT {{ url('/api/mcp/settings/{group}') }}</code>
                    <code class="d-block mb-3">DELETE {{ url('/api/mcp/cache/{type}') }}</code>
                    <p class="text-muted small mb-0">{{ __('vela::mcp.cache_types') }}</p>
                    <p class="text-muted small mb-1">{{ __('vela::mcp.auth_header') }}</p>
                    <code>Authorization: Bearer your-api-key</code>
                </div>

                @if(!$status['enabled_locked'] && !$status['api_key_locked'])
                <div class="alert alert-light border">
                    <h6 class="mb-2"><i class="fas fa-terminal mr-1"></i> {{ __('vela::mcp.env_title') }}</h6>
                    <p class="text-muted small mb-2">{{ __('vela::mcp.env_desc') }}</p>
                    <code class="d-block mb-1">MCP_ENABLED=true</code>
                    <code class="d-block">MCP_API_KEY=your-api-key</code>
                </div>
                @endif
            </div>

            @can('config_edit')
                <hr class="my-4">
                <button type="submit" class="btn btn-primary">{{ __('vela::pwa.save') }}</button>
            @endcan
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function() {
    var toggle = document.getElementById('mcp-enabled');
    var options = document.getElementById('mcp-options');
    if (toggle) {
        toggle.addEventListener('change', function() {
            options.style.display = this.checked ? '' : 'none';
        });
    }

    // Generate key
    var genBtn = document.getElementById('mcp-generate-key');
    if (genBtn) {
        genBtn.addEventListener('click', function() {
            fetch('{{ route("vela.admin.settings.mcp.generateKey") }}', {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var input = document.getElementById('mcp_api_key');
                input.value = data.key;
                input.type = 'text';
            });
        });
    }

    // Toggle visibility
    var toggleBtn = document.getElementById('mcp-toggle-key');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var input = document.getElementById('mcp_api_key');
            if (input.type === 'password') {
                input.type = 'text';
                this.querySelector('i').className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                this.querySelector('i').className = 'fas fa-eye';
            }
        });
    }

    // Clear key
    var clearBtn = document.getElementById('mcp-clear-key');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            var input = document.getElementById('mcp_api_key');
            input.value = '';
            input.type = 'text';
            input.placeholder = '{{ __("vela::mcp.key_cleared") }}';
        });
    }
})();
</script>
@endsection
