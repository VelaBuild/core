<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\CsvImportTrait;
use VelaBuild\Core\Http\Requests\MassDestroyTranslationRequest;
use VelaBuild\Core\Http\Requests\StoreTranslationRequest;
use VelaBuild\Core\Http\Requests\UpdateTranslationRequest;
use VelaBuild\Core\Models\Translation;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class TranslationsController extends Controller
{
    use CsvImportTrait;

    public function index(Request $request)
    {
        abort_if(Gate::denies('translation_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = Translation::query()->select(sprintf('%s.*', (new Translation)->table));
            $table = DataTables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'translation_show';
                $editGate      = 'translation_edit';
                $deleteGate    = 'translation_delete';
                $crudRoutePart = 'translations';

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
            $table->editColumn('lang_code', function ($row) {
                return $row->lang_code ? $row->lang_code : '';
            });
            $table->editColumn('model_type', function ($row) {
                return $row->model_type ? $row->model_type : '';
            });
            $table->editColumn('model_key', function ($row) {
                return $row->model_key ? $row->model_key : '';
            });
            $table->editColumn('translation', function ($row) {
                return $row->translation ? $row->translation : '';
            });
            $table->editColumn('notes', function ($row) {
                return $row->notes ? $row->notes : '';
            });

            $table->rawColumns(['actions', 'placeholder']);

            return $table->make(true);
        }

        return view('vela::admin.translations.index');
    }

    public function create()
    {
        abort_if(Gate::denies('translation_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::admin.translations.create');
    }

    public function store(StoreTranslationRequest $request)
    {
        $translation = Translation::create($request->all());

        return redirect()->route('vela.admin.translations.index');
    }

    public function edit(Translation $translation)
    {
        abort_if(Gate::denies('translation_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::admin.translations.edit', compact('translation'));
    }

    public function update(UpdateTranslationRequest $request, Translation $translation)
    {
        $translation->update($request->all());

        return redirect()->route('vela.admin.translations.index');
    }

    public function show(Translation $translation)
    {
        abort_if(Gate::denies('translation_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        return view('vela::admin.translations.show', compact('translation'));
    }

    public function destroy(Translation $translation)
    {
        abort_if(Gate::denies('translation_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $translation->delete();

        return back();
    }

    public function massDestroy(MassDestroyTranslationRequest $request)
    {
        $translations = Translation::find(request('ids'));

        foreach ($translations as $translation) {
            $translation->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }
}
