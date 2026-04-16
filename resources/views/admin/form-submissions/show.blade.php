@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.formSubmission.title_singular') }} {{ trans('vela::global.details') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th style="width: 200px;">
                            {{ trans('vela::cruds.formSubmission.fields.id') }}
                        </th>
                        <td>
                            {{ $formSubmission->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.formSubmission.fields.page') }}
                        </th>
                        <td>
                            @if($formSubmission->page)
                                <a href="{{ route('vela.admin.pages.edit', $formSubmission->page->id) }}">
                                    {{ $formSubmission->page->title }}
                                </a>
                            @else
                                <em>—</em>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.formSubmission.fields.created_at') }}
                        </th>
                        <td>
                            {{ $formSubmission->created_at }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.formSubmission.fields.ip_address') }}
                        </th>
                        <td>
                            {{ $formSubmission->ip_address ?? '—' }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::global.user_agent') }}
                        </th>
                        <td>
                            <small>{{ $formSubmission->user_agent ?? '—' }}</small>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.formSubmission.fields.is_read') }}
                        </th>
                        <td>
                            @if($formSubmission->is_read)
                                <span class="badge badge-success">{{ trans('vela::global.read_status') }}</span>
                            @else
                                <span class="badge badge-warning">{{ trans('vela::global.new_status') }}</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h5 class="mt-4">{{ trans('vela::global.submitted_data') }}</h5>
        <div class="card">
            <div class="card-body">
                <table class="table table-bordered">
                    <tbody>
                        @forelse($formSubmission->data ?? [] as $field => $value)
                            <tr>
                                <th style="width: 150px;">{{ ucfirst($field) }}</th>
                                <td>{{ $value }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td><em>{{ trans('vela::global.no_data') }}</em></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card-footer">
        <a class="btn btn-default" href="{{ route('vela.admin.form-submissions.index') }}">
            {{ trans('vela::global.back_to_list') }}
        </a>
        @can('form_submission_delete')
            <form action="{{ route('vela.admin.form-submissions.destroy', $formSubmission->id) }}" method="POST" onsubmit="return confirm('{{ trans('vela::global.areYouSure') }}');" style="display: inline-block;">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="submit" class="btn btn-danger" value="{{ trans('vela::global.delete') }}">
            </form>
        @endcan
    </div>
</div>

@endsection
