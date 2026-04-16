@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.content.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.contents.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.id') }}
                        </th>
                        <td>
                            {{ $content->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.title') }}
                        </th>
                        <td>
                            {{ $content->title }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.slug') }}
                        </th>
                        <td>
                            {{ $content->slug }}
                        </td>
                    </tr>

                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.description') }}
                        </th>
                        <td>
                            {{ $content->description }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.content') }}
                        </th>
                        <td>
                            {{ $content->content }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.main_image') }}
                        </th>
                        <td>
                            @if($content->main_image)
                                <a href="{{ $content->main_image->getUrl() }}" target="_blank" style="display: inline-block">
                                    <img src="{{ $content->main_image->getUrl('thumb') }}">
                                </a>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.gallery') }}
                        </th>
                        <td>
                            @foreach($content->gallery as $key => $media)
                                <a href="{{ $media->getUrl() }}" target="_blank" style="display: inline-block">
                                    <img src="{{ $media->getUrl('thumb') }}">
                                </a>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.author') }}
                        </th>
                        <td>
                            {{ $content->author->name ?? '' }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.status') }}
                        </th>
                        <td>
                            {{ $content->status ? trans('vela::global.status_' . $content->status) : '' }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.written_at') }}
                        </th>
                        <td>
                            {{ $content->written_at }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.approved_at') }}
                        </th>
                        <td>
                            {{ $content->approved_at }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.content.fields.published_at') }}
                        </th>
                        <td>
                            {{ $content->published_at }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.contents.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
        </div>
    </div>
</div>



@endsection
