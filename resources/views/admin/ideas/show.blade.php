@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.idea.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.ideas.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.idea.fields.id') }}
                        </th>
                        <td>
                            {{ $idea->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.idea.fields.name') }}
                        </th>
                        <td>
                            {{ $idea->name }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.idea.fields.details') }}
                        </th>
                        <td>
                            {{ $idea->details }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.idea.fields.category') }}
                        </th>
                        <td>
                            {{ $idea->category ? $idea->category->name : trans('vela::global.no_category') }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.idea.fields.status') }}
                        </th>
                        <td>
                            {{ $idea->status ? trans('vela::global.status_' . $idea->status) : '' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.ideas.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
                @if($idea->status !== 'created')
                    <button class="btn btn-success" id="generateContentBtn" data-idea-id="{{ $idea->id }}">
                        🤖 {{ trans('vela::global.generate_content') }}
                    </button>
                @else
                    <span class="btn btn-secondary disabled">{{ trans('vela::global.content_already_generated') }}</span>
                @endif
            </div>
        </div>
    </div>
</div>



@endsection

@section('scripts')
@parent
<script>
$(document).ready(function() {
    $('#generateContentBtn').click(function() {
        const ideaId = $(this).data('idea-id');
        const button = $(this);

        if (confirm('{{ trans('vela::global.generate_content_confirm') }}')) {
            button.prop('disabled', true).text('{{ trans('vela::global.generating') }}');

            $.ajax({
                url: '{{ route("vela.admin.ideas.generateContent") }}',
                method: 'POST',
                data: {
                    idea_id: ideaId,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        alert('{{ trans('vela::global.content_generated_success') }}');
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                        button.prop('disabled', false).text('🤖 {{ trans('vela::global.generate_content') }}');
                    }
                },
                error: function(xhr) {
                    alert('{{ trans('vela::global.error_generating_content') }}');
                    button.prop('disabled', false).text('🤖 {{ trans('vela::global.generate_content') }}');
                }
            });
        }
    });
});
</script>
@endsection
