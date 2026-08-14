<div class="block-html">
    @if(session('success'))
        <div class="vela-form-success" role="status" style="margin:0 auto 16px;max-width:640px;padding:12px 16px;border-radius:8px;background:#e7f6ec;color:#0f5132;border:1px solid #badbcc;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="vela-form-errors" role="alert" style="margin:0 auto 16px;max-width:640px;padding:12px 16px;border-radius:8px;background:#fdeaea;color:#842029;border:1px solid #f5c2c7;">
            <ul style="margin:0;padding-left:18px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- An imported section's form is stored without an action so a visitor's
         details can never be posted to the site it was copied from; the
         wiring for THIS site is added here, at render time. --}}
    {!! vela_optimize_imgs(vela_wire_imported_form($block->content['html'] ?? '', $page ?? null, $block)) !!}
</div>
