@extends('vela::layouts.admin')

@section('breadcrumb', 'Edit comment')

@section('content')
<x-vela::edit-page
    :title="\Illuminate\Support\Str::limit($comment->comment, 60)"
    subtitle="{{ trans('vela::cruds.comment.title_singular') }}"
    :breadcrumb="[
        ['label' => trans('vela::cruds.comment.title'), 'url' => route('vela.admin.comments.index')],
        ['label' => trans('vela::global.edit')],
    ]"
    avatar-fallback="{{ mb_substr($comment->user->name ?? '?', 0, 1) }}"
    :action="route('vela.admin.comments.update', $comment->id)"
    method="PUT"
    :cancel-url="route('vela.admin.comments.index')"
>
    <x-slot name="main">
        <x-vela::section title="{{ trans('vela::cruds.comment.title_singular') }}" description="Body + author.">
            <div class="form-group">
                <label class="required" for="user_id">{{ trans('vela::cruds.comment.fields.user') }}</label>
                <select class="form-control select2 {{ $errors->has('user') ? 'is-invalid' : '' }}" name="user_id" id="user_id" required>
                    @foreach($users as $id => $entry)
                        <option value="{{ $id }}" {{ (old('user_id') ?: $comment->user->id ?? '') == $id ? 'selected' : '' }}>{{ $entry }}</option>
                    @endforeach
                </select>
                @if($errors->has('user'))<div class="invalid-feedback">{{ $errors->first('user') }}</div>@endif
            </div>

            <div class="form-group">
                <label class="required" for="comment">{{ trans('vela::cruds.comment.fields.comment') }}</label>
                <input class="form-control {{ $errors->has('comment') ? 'is-invalid' : '' }}" type="text" name="comment" id="comment" value="{{ old('comment', $comment->comment) }}" required>
                @if($errors->has('comment'))<div class="invalid-feedback">{{ $errors->first('comment') }}</div>@endif
            </div>

            <div class="form-group mb-0">
                <label for="parent">{{ trans('vela::cruds.comment.fields.parent') }}</label>
                <input class="form-control {{ $errors->has('parent') ? 'is-invalid' : '' }}" type="number" name="parent" id="parent" value="{{ old('parent', $comment->parent) }}" step="1">
                @if($errors->has('parent'))<div class="invalid-feedback">{{ $errors->first('parent') }}</div>@endif
                <small class="form-text text-muted">{{ trans('vela::cruds.comment.fields.parent_helper') }}</small>
            </div>
        </x-vela::section>
    </x-slot>

    <x-slot name="side">
        <x-vela::meta-card title="Status">
            <div class="form-group mb-0">
                <select class="form-control {{ $errors->has('status') ? 'is-invalid' : '' }}" name="status" id="status" required>
                    <option value disabled {{ old('status', null) === null ? 'selected' : '' }}>{{ trans('vela::global.pleaseSelect') }}</option>
                    @foreach(\VelaBuild\Core\Models\Comment::STATUS_SELECT as $key => $label)
                        <option value="{{ $key }}" {{ old('status', $comment->status) === (string) $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                    @endforeach
                </select>
                @if($errors->has('status'))<div class="invalid-feedback">{{ $errors->first('status') }}</div>@endif
            </div>
        </x-vela::meta-card>

        <x-vela::meta-card title="Session" :body-padding="false">
            <dl class="vela-meta-list">
                <dt>IP address</dt>
                <dd><code>{{ $comment->ipaddress ?: '—' }}</code></dd>

                <dt>User agent</dt>
                <dd class="small-text">{{ $comment->useragent ?: '—' }}</dd>
            </dl>
        </x-vela::meta-card>
    </x-slot>
</x-vela::edit-page>
@endsection
