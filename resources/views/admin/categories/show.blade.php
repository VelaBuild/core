@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::global.show') }} {{ trans('vela::cruds.category.title') }}
    </div>

    <div class="card-body">
        <div class="form-group">
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.categories.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
            <table class="table table-bordered table-striped">
                <tbody>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.category.fields.id') }}
                        </th>
                        <td>
                            {{ $category->id }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.category.fields.name') }}
                        </th>
                        <td>
                            {{ $category->name }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.category.fields.icon') }}
                        </th>
                        <td>
                            {{ $category->icon }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.category.fields.image') }}
                        </th>
                        <td>
                            @if($category->image)
                                <a href="{{ $category->image->getUrl() }}" target="_blank" style="display: inline-block">
                                    <img src="{{ $category->image->getUrl('thumb') }}">
                                </a>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>
                            {{ trans('vela::cruds.category.fields.order_by') }}
                        </th>
                        <td>
                            {{ $category->order_by }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="form-group">
                <a class="btn btn-default" href="{{ route('vela.admin.categories.index') }}">
                    {{ trans('vela::global.back_to_list') }}
                </a>
            </div>
        </div>
    </div>
</div>



@endsection
