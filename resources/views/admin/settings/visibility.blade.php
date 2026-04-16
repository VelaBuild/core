@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.settings._nav')
    </div>
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-warning">{{ session('error') }}</div>
        @endif

        @php
            $mode = old('visibility_mode', $settings['visibility_mode'] ?? 'public');
            $noindex = old('visibility_noindex', $settings['visibility_noindex'] ?? '0') === '1';
            $blockAi = old('visibility_block_ai', $settings['visibility_block_ai'] ?? '0') === '1';
            $holdingPage = old('visibility_holding_page', $settings['visibility_holding_page'] ?? '0') === '1';
            $holdingPageId = old('visibility_holding_page_id', $settings['visibility_holding_page_id'] ?? '');
        @endphp

        <form action="{{ route('vela.admin.settings.updateGroup', 'visibility') }}" method="POST" id="visibility-form">
            @csrf

            {{-- Mode selection --}}
            <div class="form-group">
                <label class="d-block mb-2"><strong>{{ __('vela::visibility.mode_label') }}</strong></label>

                <div class="custom-control custom-radio mb-2">
                    <input type="radio" class="custom-control-input" id="mode-public" name="visibility_mode" value="public"
                        {{ $mode === 'public' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="mode-public">
                        <i class="fas fa-globe text-success mr-1"></i>
                        {{ __('vela::visibility.mode_public') }}
                    </label>
                    <small class="form-text text-muted ml-4">{{ __('vela::visibility.mode_public_desc') }}</small>
                </div>

                <div class="custom-control custom-radio mb-2">
                    <input type="radio" class="custom-control-input" id="mode-restricted" name="visibility_mode" value="restricted"
                        {{ $mode === 'restricted' ? 'checked' : '' }}>
                    <label class="custom-control-label" for="mode-restricted">
                        <i class="fas fa-lock text-warning mr-1"></i>
                        {{ __('vela::visibility.mode_restricted') }}
                    </label>
                    <small class="form-text text-muted ml-4">{{ __('vela::visibility.mode_restricted_desc') }}</small>
                </div>
            </div>

            {{-- Restricted sub-options --}}
            <div id="restricted-options" class="ml-4 pl-3 border-left border-warning" style="{{ $mode !== 'restricted' ? 'display:none' : '' }}">
                <p class="text-muted mb-3"><small>{{ __('vela::visibility.suboptions_help') }}</small></p>

                {{-- Noindex --}}
                <div class="custom-control custom-checkbox mb-3">
                    <input type="hidden" name="visibility_noindex" value="0">
                    <input type="checkbox" class="custom-control-input" id="opt-noindex" name="visibility_noindex" value="1"
                        {{ $noindex ? 'checked' : '' }}>
                    <label class="custom-control-label" for="opt-noindex">
                        {{ __('vela::visibility.opt_noindex') }}
                    </label>
                    <small class="form-text text-muted">{{ __('vela::visibility.opt_noindex_desc') }}</small>
                </div>

                {{-- Block AI --}}
                <div class="custom-control custom-checkbox mb-3">
                    <input type="hidden" name="visibility_block_ai" value="0">
                    <input type="checkbox" class="custom-control-input" id="opt-block-ai" name="visibility_block_ai" value="1"
                        {{ $blockAi ? 'checked' : '' }}>
                    <label class="custom-control-label" for="opt-block-ai">
                        {{ __('vela::visibility.opt_block_ai') }}
                    </label>
                    <small class="form-text text-muted">{{ __('vela::visibility.opt_block_ai_desc') }}</small>
                </div>

                {{-- Holding page --}}
                <div class="custom-control custom-checkbox mb-3">
                    <input type="hidden" name="visibility_holding_page" value="0">
                    <input type="checkbox" class="custom-control-input" id="opt-holding" name="visibility_holding_page" value="1"
                        {{ $holdingPage ? 'checked' : '' }}>
                    <label class="custom-control-label" for="opt-holding">
                        {{ __('vela::visibility.opt_holding') }}
                    </label>
                    <small class="form-text text-muted">{{ __('vela::visibility.opt_holding_desc') }}</small>
                </div>

                {{-- Holding page picker --}}
                <div id="holding-page-picker" class="form-group ml-4" style="{{ !$holdingPage ? 'display:none' : '' }}">
                    <label for="visibility_holding_page_id">{{ __('vela::visibility.holding_page_select') }}</label>
                    <select class="form-control" name="visibility_holding_page_id" id="visibility_holding_page_id">
                        <option value="">-- {{ __('vela::visibility.holding_page_none') }} --</option>
                        @foreach($pages as $page)
                            <option value="{{ $page->id }}" {{ (string)$holdingPageId === (string)$page->id ? 'selected' : '' }}>
                                {{ $page->title }} (/{{ $page->slug }}){{ $page->status !== 'published' ? ' [' . ucfirst($page->status) . ']' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <small class="form-text text-muted">{{ __('vela::visibility.holding_page_help') }}</small>
                </div>
            </div>

            <hr class="my-4">

            {{-- x402 AI Payment --}}
            @php
                $x402Enabled = old('x402_enabled', $settings['x402_enabled'] ?? '0') === '1';
                $x402PayTo = old('x402_pay_to', $settings['x402_pay_to'] ?? '');
                $x402Price = old('x402_price_usd', $settings['x402_price_usd'] ?? '0.01');
                $x402Network = old('x402_network', $settings['x402_network'] ?? 'base');
                $x402Desc = old('x402_description', $settings['x402_description'] ?? '');
            @endphp

            <div class="form-group">
                <label class="d-block mb-2"><strong>{{ __('vela::visibility.x402_title') }}</strong></label>
                <small class="form-text text-muted mb-3 d-block">{{ __('vela::visibility.x402_intro') }}</small>

                <div class="custom-control custom-switch mb-3">
                    <input type="hidden" name="x402_enabled" value="0">
                    <input type="checkbox" class="custom-control-input" id="x402-enabled" name="x402_enabled" value="1"
                        {{ $x402Enabled ? 'checked' : '' }}>
                    <label class="custom-control-label" for="x402-enabled">
                        {{ __('vela::visibility.x402_enable') }}
                    </label>
                </div>
            </div>

            <div id="x402-options" style="{{ !$x402Enabled ? 'display:none' : '' }}">
                <div class="form-group">
                    <label for="x402_pay_to">{{ __('vela::visibility.x402_wallet') }}</label>
                    <input type="text" class="form-control" name="x402_pay_to" id="x402_pay_to"
                        value="{{ $x402PayTo }}" placeholder="0x...">
                    <small class="form-text text-muted">{{ __('vela::visibility.x402_wallet_help') }}</small>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="x402_price_usd">{{ __('vela::visibility.x402_price') }}</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                <input type="number" class="form-control" name="x402_price_usd" id="x402_price_usd"
                                    value="{{ $x402Price }}" step="0.001" min="0.001" max="1000">
                            </div>
                            <small class="form-text text-muted">{{ __('vela::visibility.x402_price_help') }}</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="x402_network">{{ __('vela::visibility.x402_network') }}</label>
                            <select class="form-control" name="x402_network" id="x402_network">
                                <option value="base" {{ $x402Network === 'base' ? 'selected' : '' }}>Base</option>
                                <option value="ethereum" {{ $x402Network === 'ethereum' ? 'selected' : '' }}>Ethereum</option>
                                <option value="polygon" {{ $x402Network === 'polygon' ? 'selected' : '' }}>Polygon</option>
                                <option value="arbitrum" {{ $x402Network === 'arbitrum' ? 'selected' : '' }}>Arbitrum</option>
                                <option value="optimism" {{ $x402Network === 'optimism' ? 'selected' : '' }}>Optimism</option>
                            </select>
                            <small class="form-text text-muted">{{ __('vela::visibility.x402_network_help') }}</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="x402_description">{{ __('vela::visibility.x402_description') }}</label>
                    <input type="text" class="form-control" name="x402_description" id="x402_description"
                        value="{{ $x402Desc }}" placeholder="Access to website content">
                    <small class="form-text text-muted">{{ __('vela::visibility.x402_description_help') }}</small>
                </div>
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
    var modePublic = document.getElementById('mode-public');
    var modeRestricted = document.getElementById('mode-restricted');
    var restrictedOpts = document.getElementById('restricted-options');
    var optHolding = document.getElementById('opt-holding');
    var holdingPicker = document.getElementById('holding-page-picker');

    function toggleRestricted() {
        restrictedOpts.style.display = modeRestricted.checked ? '' : 'none';
    }
    function toggleHolding() {
        holdingPicker.style.display = optHolding.checked ? '' : 'none';
    }

    modePublic.addEventListener('change', toggleRestricted);
    modeRestricted.addEventListener('change', toggleRestricted);
    optHolding.addEventListener('change', toggleHolding);

    // x402 toggle
    var x402Toggle = document.getElementById('x402-enabled');
    var x402Options = document.getElementById('x402-options');
    x402Toggle.addEventListener('change', function() {
        x402Options.style.display = this.checked ? '' : 'none';
    });
})();
</script>
@endsection
