@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.user.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.users.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.id') }}
                        </th>
                        <td>
                            {{ $user->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.name') }}
                        </th>
                        <td>
                            {{ $user->name }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.email') }}
                        </th>
                        <td>
                            {{ $user->email }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.email_verified_at') }}
                        </th>
                        <td>
                            {{ $user->email_verified_at }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.two_factor') }}
                        </th>
                        <td>
                            <input type="checkbox" disabled="disabled" {{ $user->two_factor ? 'checked' : '' }}>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.roles') }}
                        </th>
                        <td>
                            @foreach($user->roles as $key => $roles)
                                <span class="label label-info">{{ $roles->title }}</span>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.last_login_at') }}
                        </th>
                        <td>
                            {{ $user->last_login_at }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.last_ip') }}
                        </th>
                        <td>
                            {{ $user->last_ip }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.useragent') }}
                        </th>
                        <td>
                            {{ $user->useragent }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.profile_pic') }}
                        </th>
                        <td>
                            @if($user->profile_pic)
                                <a href="{{ $user->profile_pic->getUrl() }}" target="_blank" style="display: inline-block">
                                    <img src="{{ $user->profile_pic->getUrl('thumb') }}">
                                </a>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.bio') }}
                        </th>
                        <td>
                            {!! $user->bio !!}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.user.fields.subscribe_newsletter') }}
                        </th>
                        <td>
                            <input type="checkbox" disabled="disabled" {{ $user->subscribe_newsletter ? 'checked' : '' }}>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.users.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.relatedData') }}
    </div>
    <ul class="nav nav-tabs" role="tablist" id="relationship-tabs">
        <li class="nav-item">
            <a class="nav-link" href="#author_contents" role="tab" data-toggle="tab">
                {{ trans('vela::cruds.content.title') }}
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#user_comments" role="tab" data-toggle="tab">
                {{ trans('vela::cruds.comment.title') }}
            </a>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane" role="tabpanel" id="author_contents">
            @includeIf('vela::admin.users.relationships.authorContents', ['contents' => $user->authorContents])
        </div>
        <div class="tab-pane" role="tabpanel" id="user_comments">
            @includeIf('vela::admin.users.relationships.userComments', ['comments' => $user->userComments])
        </div>
    </div>
</div>

@endsection
