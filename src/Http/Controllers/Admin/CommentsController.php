<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Requests\MassDestroyCommentRequest;
use VelaBuild\Core\Http\Requests\StoreCommentRequest;
use VelaBuild\Core\Http\Requests\UpdateCommentRequest;
use VelaBuild\Core\Models\Comment;
use VelaBuild\Core\Models\VelaUser;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class CommentsController extends Controller
{
    public function index(Request $request)
    {
        abort_if(Gate::denies('comment_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = Comment::with(['user'])->select(sprintf('%s.*', (new Comment)->table));
            $table = Datatables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'comment_show';
                $editGate      = 'comment_edit';
                $deleteGate    = 'comment_delete';
                $crudRoutePart = 'comments';

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
            $table->addColumn('user_name', function ($row) {
                return $row->user ? $row->user->name : '';
            });

            $table->editColumn('comment', function ($row) {
                return $row->comment ? $row->comment : '';
            });
            $table->editColumn('status', function ($row) {
                return $row->status ? __('vela::global.status_' . $row->status) : '';
            });
            $table->editColumn('parent', function ($row) {
                return $row->parent ? $row->parent : '';
            });

            $table->rawColumns(['actions', 'placeholder', 'user']);

            return $table->make(true);
        }

        return view('vela::admin.comments.index');
    }

    public function create()
    {
        abort_if(Gate::denies('comment_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $users = VelaUser::pluck('name', 'id')->prepend(trans('vela::global.pleaseSelect'), '');

        return view('vela::admin.comments.create', compact('users'));
    }

    public function store(StoreCommentRequest $request)
    {
        $comment = Comment::create($request->all());

        return redirect()->route('vela.admin.comments.index');
    }

    public function edit(Comment $comment)
    {
        abort_if(Gate::denies('comment_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $users = VelaUser::pluck('name', 'id')->prepend(trans('vela::global.pleaseSelect'), '');

        $comment->load('user');

        return view('vela::admin.comments.edit', compact('comment', 'users'));
    }

    public function update(UpdateCommentRequest $request, Comment $comment)
    {
        $comment->update($request->all());

        return redirect()->route('vela.admin.comments.index');
    }

    public function show(Comment $comment)
    {
        abort_if(Gate::denies('comment_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $comment->load('user');

        return view('vela::admin.comments.show', compact('comment'));
    }

    public function destroy(Comment $comment)
    {
        abort_if(Gate::denies('comment_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $comment->delete();

        return back();
    }

    public function massDestroy(MassDestroyCommentRequest $request)
    {
        $comments = Comment::find(request('ids'));

        foreach ($comments as $comment) {
            $comment->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }
}
