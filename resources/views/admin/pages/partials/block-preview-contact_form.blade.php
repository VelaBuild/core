@php
    $settings = $block->settings ?? [];
    $fields = $settings['fields'] ?? [];
    $submitLabel = $settings['submit_label'] ?? trans('vela::global.send_message');
@endphp
<div class="preview-contact-form">
    @foreach(['name', 'email', 'phone', 'subject', 'message'] as $fieldName)
        @if(!empty($fields[$fieldName]['enabled']))
            <div class="mb-2">
                <label class="mb-0"><small>{{ ucfirst($fieldName) }}@if(!empty($fields[$fieldName]['required'])) *@endif</small></label>
                @if($fieldName === 'message')
                    <textarea class="form-control form-control-sm" rows="2" disabled></textarea>
                @else
                    <input type="text" class="form-control form-control-sm" disabled>
                @endif
            </div>
        @endif
    @endforeach
    <button class="btn btn-sm btn-primary mt-1" disabled>{{ e($submitLabel) }}</button>
</div>
