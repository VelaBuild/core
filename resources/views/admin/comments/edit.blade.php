@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.edit') }} {{ trans('vela::cruds.comment.title_singular') }}
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route("vela.admin.comments.update", [$comment->id]) }}" enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <div class="form-group">
                <label class="required" for="user_id">{{ trans('vela::cruds.comment.fields.user') }}</label>
                <select class="form-control select2 {{ $errors->has('user') ? 'is-invalid' : '' }}" name="user_id" id="user_id" required>
                    @foreach($users as $id => $entry)
                        <option value="{{ $id }}" {{ (old('user_id') ? old('user_id') : $comment->user->id ?? '') == $id ? 'selected' : '' }}>{{ $entry }}</option>
                    @endforeach
                </select>
                @if($errors->has('user'))
                    <div class="invalid-feedback">
                        {{ $errors->first('user') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.comment.fields.user_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required" for="comment">{{ trans('vela::cruds.comment.fields.comment') }}</label>
                <input class="form-control {{ $errors->has('comment') ? 'is-invalid' : '' }}" type="text" name="comment" id="comment" value="{{ old('comment', $comment->comment) }}" required>
                @if($errors->has('comment'))
                    <div class="invalid-feedback">
                        {{ $errors->first('comment') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.comment.fields.comment_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required">{{ trans('vela::cruds.comment.fields.status') }}</label>
                <select class="form-control {{ $errors->has('status') ? 'is-invalid' : '' }}" name="status" id="status" required>
                    <option value disabled {{ old('status', null) === null ? 'selected' : '' }}>{{ trans('vela::global.pleaseSelect') }}</option>
                    @foreach(\VelaBuild\Core\Models\Comment::STATUS_SELECT as $key => $label)
                        <option value="{{ $key }}" {{ old('status', $comment->status) === (string) $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                    @endforeach
                </select>
                @if($errors->has('status'))
                    <div class="invalid-feedback">
                        {{ $errors->first('status') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.comment.fields.status_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="useragent">{{ trans('vela::cruds.comment.fields.useragent') }}</label>
                <input class="form-control {{ $errors->has('useragent') ? 'is-invalid' : '' }}" type="text" name="useragent" id="useragent" value="{{ old('useragent', $comment->useragent) }}">
                @if($errors->has('useragent'))
                    <div class="invalid-feedback">
                        {{ $errors->first('useragent') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.comment.fields.useragent_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="ipaddress">{{ trans('vela::cruds.comment.fields.ipaddress') }}</label>
                <input class="form-control {{ $errors->has('ipaddress') ? 'is-invalid' : '' }}" type="text" name="ipaddress" id="ipaddress" value="{{ old('ipaddress', $comment->ipaddress) }}">
                @if($errors->has('ipaddress'))
                    <div class="invalid-feedback">
                        {{ $errors->first('ipaddress') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.comment.fields.ipaddress_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="parent">{{ trans('vela::cruds.comment.fields.parent') }}</label>
                <input class="form-control {{ $errors->has('parent') ? 'is-invalid' : '' }}" type="number" name="parent" id="parent" value="{{ old('parent', $comment->parent) }}" step="1">
                @if($errors->has('parent'))
                    <div class="invalid-feedback">
                        {{ $errors->first('parent') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.comment.fields.parent_helper') }}</span>
            </div>
            <div class="form-group">
                <button class="btn btn-danger" type="submit">
                    {{ trans('vela::global.save') }}
                </button>
            </div>
        </form>
    </div>
</div>



@endsection
