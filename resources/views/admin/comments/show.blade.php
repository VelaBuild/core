@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.comment.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.comments.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.id') }}
                        </th>
                        <td>
                            {{ $comment->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.user') }}
                        </th>
                        <td>
                            {{ $comment->user->name ?? '' }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.comment') }}
                        </th>
                        <td>
                            {{ $comment->comment }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.status') }}
                        </th>
                        <td>
                            {{ $comment->status ? trans('vela::global.status_' . $comment->status) : '' }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.useragent') }}
                        </th>
                        <td>
                            {{ $comment->useragent }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.ipaddress') }}
                        </th>
                        <td>
                            {{ $comment->ipaddress }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.comment.fields.parent') }}
                        </th>
                        <td>
                            {{ $comment->parent }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.comments.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
        </div>
    </div>
</div>



@endsection
