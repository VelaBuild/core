@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.edit') }} {{ trans('vela::cruds.idea.title_singular') }}
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route("vela.admin.ideas.update", [$idea->id]) }}" enctype="multipart/form-data">
            @method('PUT')
            @csrf
            <div class="form-group">
                <label class="required" for="name">{{ trans('vela::cruds.idea.fields.name') }}</label>
                <input class="form-control {{ $errors->has('name') ? 'is-invalid' : '' }}" type="text" name="name" id="name" value="{{ old('name', $idea->name) }}" required>
                @if($errors->has('name'))
                    <div class="invalid-feedback">
                        {{ $errors->first('name') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.idea.fields.name_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="details">{{ trans('vela::cruds.idea.fields.details') }}</label>
                <textarea class="form-control {{ $errors->has('details') ? 'is-invalid' : '' }}" name="details" id="details">{{ old('details', $idea->details) }}</textarea>
                @if($errors->has('details'))
                    <div class="invalid-feedback">
                        {{ $errors->first('details') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.idea.fields.details_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="keyword">{{ trans('vela::cruds.idea.fields.keyword') }}</label>
                <input type="text" class="form-control {{ $errors->has('keyword') ? 'is-invalid' : '' }}" name="keyword" id="keyword" value="{{ old('keyword', $idea->keyword) }}">
                @if($errors->has('keyword'))
                    <div class="invalid-feedback">
                        {{ $errors->first('keyword') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.idea.fields.keyword_helper') }}</span>
            </div>
            <div class="form-group">
                <label for="category_id">{{ trans('vela::cruds.idea.fields.category') }}</label>
                <select class="form-control {{ $errors->has('category_id') ? 'is-invalid' : '' }}" name="category_id" id="category_id">
                    <option value="">{{ trans('vela::global.pleaseSelect') }}</option>
                    @foreach(\VelaBuild\Core\Models\Category::all() as $category)
                        <option value="{{ $category->id }}" {{ old('category_id', $idea->category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                    @endforeach
                </select>
                @if($errors->has('category_id'))
                    <div class="invalid-feedback">
                        {{ $errors->first('category_id') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.idea.fields.category_helper') }}</span>
            </div>
            <div class="form-group">
                <label class="required">{{ trans('vela::cruds.idea.fields.status') }}</label>
                <select class="form-control {{ $errors->has('status') ? 'is-invalid' : '' }}" name="status" id="status" required>
                    <option value disabled {{ old('status', null) === null ? 'selected' : '' }}>{{ trans('vela::global.pleaseSelect') }}</option>
                    @foreach(\VelaBuild\Core\Models\Idea::STATUS_SELECT as $key => $label)
                        <option value="{{ $key }}" {{ old('status', $idea->status) === (string) $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                    @endforeach
                </select>
                @if($errors->has('status'))
                    <div class="invalid-feedback">
                        {{ $errors->first('status') }}
                    </div>
                @endif
                <span class="help-block">{{ trans('vela::cruds.idea.fields.status_helper') }}</span>
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
