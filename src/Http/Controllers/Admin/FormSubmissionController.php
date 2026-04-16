<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Requests\MassDestroyFormSubmissionRequest;
use VelaBuild\Core\Models\FormSubmission;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class FormSubmissionController extends Controller
{
    public function index(Request $request)
    {
        abort_if(Gate::denies('form_submission_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = FormSubmission::with('page')->select(sprintf('%s.*', (new FormSubmission)->table));

            $table = DataTables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'form_submission_show';
                $editGate      = null;
                $deleteGate    = 'form_submission_delete';
                $crudRoutePart = 'form-submissions';

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

            $table->addColumn('page_title', function ($row) {
                return $row->page ? $row->page->title : '';
            });

            $table->editColumn('preview', function ($row) {
                $data    = $row->data ?? [];
                $message = $data['message'] ?? $data['email'] ?? (is_array($data) ? implode(' ', array_values($data)) : '');
                return mb_substr(strip_tags($message), 0, 50);
            });

            $table->editColumn('ip_address', function ($row) {
                return $row->ip_address ? $row->ip_address : '';
            });

            $table->editColumn('is_read', function ($row) {
                if ($row->is_read) {
                    return '<span class="badge badge-success">Read</span>';
                }
                return '<span class="badge badge-warning">New</span>';
            });

            $table->editColumn('created_at', function ($row) {
                return $row->created_at ? $row->created_at : '';
            });

            $table->rawColumns(['actions', 'placeholder', 'is_read']);

            return $table->make(true);
        }

        return view('vela::admin.form-submissions.index');
    }

    public function show(FormSubmission $formSubmission)
    {
        abort_if(Gate::denies('form_submission_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $formSubmission->update(['is_read' => true]);

        return view('vela::admin.form-submissions.show', compact('formSubmission'));
    }

    public function destroy(FormSubmission $formSubmission)
    {
        abort_if(Gate::denies('form_submission_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $formSubmission->delete();

        return back();
    }

    public function massDestroy(MassDestroyFormSubmissionRequest $request)
    {
        $submissions = FormSubmission::find(request('ids'));

        foreach ($submissions as $submission) {
            $submission->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }
}
