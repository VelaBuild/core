<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\CsvImportTrait;
use VelaBuild\Core\Http\Controllers\Traits\MediaUploadingTrait;
use VelaBuild\Core\Http\Requests\MassDestroyUserRequest;
use VelaBuild\Core\Http\Requests\StoreUserRequest;
use VelaBuild\Core\Http\Requests\UpdateUserRequest;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use Gate;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class UsersController extends Controller
{
    use MediaUploadingTrait, CsvImportTrait;

    public function index(Request $request)
    {
        abort_if(Gate::denies('user_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = VelaUser::with(['roles'])->select(sprintf('%s.*', (new VelaUser)->table));
            $table = Datatables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'user_show';
                $editGate      = 'user_edit';
                $deleteGate    = 'user_delete';
                $crudRoutePart = 'users';

                return view('vela::partials.datatablesActions', compact(
                    'viewGate',
                    'editGate',
                    'deleteGate',
                    'crudRoutePart',
                    'row'
                ));
            });

            $table->editColumn('id', function ($row) {
                return $row->id ? $row->id : '';
            });
            $table->editColumn('name', function ($row) {
                return $row->name ? $row->name : '';
            });
            $table->editColumn('email', function ($row) {
                return $row->email ? $row->email : '';
            });

            $table->editColumn('two_factor', function ($row) {
                return '<input type="checkbox" disabled ' . ($row->two_factor ? 'checked' : null) . '>';
            });
            $table->editColumn('roles', function ($row) {
                $labels = [];
                foreach ($row->roles as $role) {
                    $labels[] = sprintf('<span class="label label-info label-many">%s</span>', $role->title);
                }

                return implode(' ', $labels);
            });

            $table->editColumn('profile_pic', function ($row) {
                if ($photo = $row->profile_pic) {
                    return sprintf(
                        '<a href="%s" target="_blank"><img src="%s" width="50px" height="50px"></a>',
                        $photo->url,
                        $photo->thumbnail
                    );
                }

                return '';
            });
            $table->editColumn('subscribe_newsletter', function ($row) {
                return '<input type="checkbox" disabled ' . ($row->subscribe_newsletter ? 'checked' : null) . '>';
            });

            $table->rawColumns(['actions', 'placeholder', 'two_factor', 'roles', 'profile_pic', 'subscribe_newsletter']);

            return $table->make(true);
        }

        return view('vela::admin.users.index');
    }

    public function create()
    {
        abort_if(Gate::denies('user_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $roles = Role::pluck('title', 'id');

        return view('vela::admin.users.create', compact('roles'));
    }

    public function store(StoreUserRequest $request)
    {
        $user = VelaUser::create($request->all());
        $user->roles()->sync($request->input('roles', []));
        if ($request->input('profile_pic', false)) {
            $user->addMedia(storage_path('tmp/uploads/' . basename($request->input('profile_pic'))))->toMediaCollection('profile_pic');
        }

        if ($media = $request->input('ck-media', false)) {
            Media::whereIn('id', $media)->update(['model_id' => $user->id]);
        }

        return redirect()->route('vela.admin.users.index');
    }

    public function edit(VelaUser $user)
    {
        abort_if(Gate::denies('user_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $roles = Role::pluck('title', 'id');

        $user->load('roles');

        return view('vela::admin.users.edit', compact('roles', 'user'));
    }

    public function update(UpdateUserRequest $request, VelaUser $user)
    {
        $user->update($request->all());
        $user->roles()->sync($request->input('roles', []));
        if ($request->input('profile_pic', false)) {
            if (! $user->profile_pic || $request->input('profile_pic') !== $user->profile_pic->file_name) {
                if ($user->profile_pic) {
                    $user->profile_pic->delete();
                }
                $user->addMedia(storage_path('tmp/uploads/' . basename($request->input('profile_pic'))))->toMediaCollection('profile_pic');
            }
        } elseif ($user->profile_pic) {
            $user->profile_pic->delete();
        }

        return redirect()->route('vela.admin.users.index');
    }

    public function show(VelaUser $user)
    {
        abort_if(Gate::denies('user_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $user->load('roles', 'authorContents', 'userComments');

        return view('vela::admin.users.show', compact('user'));
    }

    public function destroy(VelaUser $user)
    {
        abort_if(Gate::denies('user_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $user->delete();

        return back();
    }

    public function massDestroy(MassDestroyUserRequest $request)
    {
        $users = VelaUser::find(request('ids'));

        foreach ($users as $user) {
            $user->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }

    public function storeCKEditorImages(Request $request)
    {
        abort_if(Gate::denies('user_create') && Gate::denies('user_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $model         = new VelaUser();
        $model->id     = $request->input('crud_id', 0);
        $model->exists = true;
        $media         = $model->addMediaFromRequest('upload')->toMediaCollection('ck-media');

        return response()->json(['id' => $media->id, 'url' => $media->getUrl()], Response::HTTP_CREATED);
    }
}
