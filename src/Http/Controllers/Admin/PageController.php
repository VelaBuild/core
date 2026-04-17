<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\MediaUploadingTrait;
use VelaBuild\Core\Http\Requests\MassDestroyPageRequest;
use VelaBuild\Core\Http\Requests\StorePageRequest;
use VelaBuild\Core\Http\Requests\UpdatePageRequest;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class PageController extends Controller
{
    use MediaUploadingTrait;

    public function index(Request $request)
    {
        abort_if(Gate::denies('page_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = Page::query()->select(sprintf('%s.*', (new Page)->table));

            $table = DataTables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'page_show';
                $editGate      = 'page_edit';
                $deleteGate    = 'page_delete';
                $crudRoutePart = 'pages';
                $viewUrl       = url($row->slug === 'home' ? '/' : $row->slug);
                $viewNewTab    = true;

                return view('vela::partials.datatablesActions', compact(
                    'viewGate',
                    'editGate',
                    'deleteGate',
                    'crudRoutePart',
                    'row',
                    'viewUrl',
                    'viewNewTab'
                ));
            });

            $table->editColumn('id', function ($row) {
                return $row->id ? $row->id : '';
            });
            $table->editColumn('title', function ($row) {
                return $row->title ? $row->title : '';
            });
            $table->editColumn('slug', function ($row) {
                return $row->slug ? $row->slug : '';
            });
            $table->editColumn('locale', function ($row) {
                return $row->locale ? $row->locale : '';
            });
            $table->editColumn('status', function ($row) {
                $badgeClass = [
                    'draft'     => 'badge-secondary',
                    'published' => 'badge-success',
                    'unlisted'  => 'badge-warning',
                ][$row->status] ?? 'badge-secondary';

                return '<span class="badge ' . $badgeClass . '">' . __('vela::global.status_' . $row->status) . '</span>';
            });
            $table->editColumn('order_column', function ($row) {
                return $row->order_column;
            });

            $table->rawColumns(['actions', 'placeholder', 'status']);

            return $table->make(true);
        }

        return view('vela::admin.pages.index');
    }

    public function create()
    {
        abort_if(Gate::denies('page_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $locales = config('vela.available_languages');
        $pages   = Page::whereNull('parent_id')->pluck('title', 'id');

        return view('vela::admin.pages.create', compact('locales', 'pages'));
    }

    public function store(StorePageRequest $request)
    {
        $data = $request->only([
            'title', 'slug', 'locale', 'status', 'meta_title',
            'meta_description', 'custom_css', 'custom_js', 'order_column', 'parent_id',
        ]);

        if (config('vela.x402.enabled') && config('vela.x402.mode') === 'per_page') {
            $data['x402_enabled'] = $request->boolean('x402_enabled') ? 1 : 0;
            $data['x402_price_usd'] = $request->input('x402_price_usd') ?: null;
        }

        $page = Page::create($data);

        if ($request->filled('og_image_media_id')) {
            $sourceMedia = Media::find($request->input('og_image_media_id'));
            if ($sourceMedia) {
                $sourceMedia->copy($page, 'og_image');
            }
        } elseif ($request->input('og_image', false)) {
            $page->addMedia(storage_path('tmp/uploads/' . basename($request->input('og_image'))))
                ->toMediaCollection('og_image');
        }

        if ($media = $request->input('ck-media', false)) {
            Media::whereIn('id', $media)->update(['model_id' => $page->id]);
        }

        $rowsData = json_decode($request->input('rows', '[]'), true) ?? [];

        DB::transaction(function () use ($page, $rowsData) {
            foreach ($rowsData as $rowOrder => $rowData) {
                $pageRow = PageRow::create([
                    'page_id'          => $page->id,
                    'name'             => $rowData['name'] ?? null,
                    'css_class'        => $rowData['css_class'] ?? null,
                    'background_color' => $rowData['background_color'] ?? null,
                    'background_image' => $rowData['background_image'] ?? null,
                    'text_color'       => $rowData['text_color'] ?? null,
                    'text_alignment'   => $rowData['text_alignment'] ?? null,
                    'padding'          => $rowData['padding'] ?? null,
                    'order_column'     => $rowData['order'] ?? $rowOrder,
                ]);

                $blocks = $rowData['blocks'] ?? [];
                foreach ($blocks as $blockOrder => $blockData) {
                    $pageRow->blocks()->create([
                        'column_index'     => $blockData['column_index'] ?? 0,
                        'column_width'     => $blockData['column_width'] ?? 12,
                        'order_column'     => $blockData['order'] ?? $blockOrder,
                        'type'             => $blockData['type'],
                        'content'          => isset($blockData['content']) ? (is_array($blockData['content']) ? $blockData['content'] : json_decode($blockData['content'], true)) : null,
                        'settings'         => isset($blockData['settings']) ? (is_array($blockData['settings']) ? $blockData['settings'] : json_decode($blockData['settings'], true)) : null,
                        'background_color' => $blockData['background_color'] ?? null,
                        'background_image' => $blockData['background_image'] ?? null,
                        'text_color'       => $blockData['text_color'] ?? null,
                        'text_alignment'   => $blockData['text_alignment'] ?? null,
                        'padding'          => $blockData['padding'] ?? null,
                    ]);
                }
            }
        });

        return redirect()->route('vela.admin.pages.index');
    }

    public function edit(Page $page)
    {
        abort_if(Gate::denies('page_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $page->load(['rows.blocks']);

        $locales = config('vela.available_languages');
        $pages   = Page::whereNull('parent_id')->where('id', '!=', $page->id)->pluck('title', 'id');

        return view('vela::admin.pages.edit', compact('page', 'locales', 'pages'));
    }

    public function update(UpdatePageRequest $request, Page $page)
    {
        $data = $request->only([
            'title', 'slug', 'locale', 'status', 'meta_title',
            'meta_description', 'custom_css', 'custom_js', 'order_column', 'parent_id',
        ]);

        if (config('vela.x402.enabled') && config('vela.x402.mode') === 'per_page') {
            $data['x402_enabled'] = $request->boolean('x402_enabled') ? 1 : 0;
            $data['x402_price_usd'] = $request->input('x402_price_usd') ?: null;
        }

        $page->update($data);

        if ($request->filled('og_image_media_id')) {
            if ($page->og_image) {
                $page->og_image->delete();
            }
            $sourceMedia = Media::find($request->input('og_image_media_id'));
            if ($sourceMedia) {
                $sourceMedia->copy($page, 'og_image');
            }
        } elseif ($request->input('og_image', false)) {
            if (! $page->og_image || $request->input('og_image') !== $page->og_image->file_name) {
                if ($page->og_image) {
                    $page->og_image->delete();
                }
                $page->addMedia(storage_path('tmp/uploads/' . basename($request->input('og_image'))))
                    ->toMediaCollection('og_image');
            }
        } elseif ($page->og_image) {
            $page->og_image->delete();
        }

        if ($media = $request->input('ck-media', false)) {
            Media::whereIn('id', $media)->update(['model_id' => $page->id]);
        }

        $rowsData = json_decode($request->input('rows', '[]'), true) ?? [];

        DB::transaction(function () use ($page, $rowsData) {
            $existingRowIds   = $page->rows()->pluck('id')->toArray();
            $submittedRowIds  = [];

            foreach ($rowsData as $rowOrder => $rowData) {
                $rowId = $rowData['id'] ?? null;

                $rowPayload = [
                    'name'             => $rowData['name'] ?? null,
                    'css_class'        => $rowData['css_class'] ?? null,
                    'background_color' => $rowData['background_color'] ?? null,
                    'background_image' => $rowData['background_image'] ?? null,
                    'text_color'       => $rowData['text_color'] ?? null,
                    'text_alignment'   => $rowData['text_alignment'] ?? null,
                    'padding'          => $rowData['padding'] ?? null,
                    'order_column'     => $rowData['order'] ?? $rowOrder,
                ];

                if ($rowId && is_numeric($rowId) && in_array((int) $rowId, $existingRowIds)) {
                    $pageRow = PageRow::find((int) $rowId);
                    $pageRow->update($rowPayload);
                } else {
                    $pageRow = PageRow::create(array_merge(['page_id' => $page->id], $rowPayload));
                }

                $submittedRowIds[] = $pageRow->id;

                $existingBlockIds  = $pageRow->blocks()->pluck('id')->toArray();
                $submittedBlockIds = [];
                $blocks            = $rowData['blocks'] ?? [];

                foreach ($blocks as $blockOrder => $blockData) {
                    $blockId = $blockData['id'] ?? null;

                    $blockPayload = [
                        'column_index'     => $blockData['column_index'] ?? 0,
                        'column_width'     => $blockData['column_width'] ?? 12,
                        'order_column'     => $blockData['order'] ?? $blockOrder,
                        'type'             => $blockData['type'],
                        'content'          => isset($blockData['content']) ? (is_array($blockData['content']) ? $blockData['content'] : json_decode($blockData['content'], true)) : null,
                        'settings'         => isset($blockData['settings']) ? (is_array($blockData['settings']) ? $blockData['settings'] : json_decode($blockData['settings'], true)) : null,
                        'background_color' => $blockData['background_color'] ?? null,
                        'background_image' => $blockData['background_image'] ?? null,
                        'text_color'       => $blockData['text_color'] ?? null,
                        'text_alignment'   => $blockData['text_alignment'] ?? null,
                        'padding'          => $blockData['padding'] ?? null,
                    ];

                    if ($blockId && is_numeric($blockId) && in_array((int) $blockId, $existingBlockIds)) {
                        $pageBlock = PageBlock::find((int) $blockId);
                        $pageBlock->update($blockPayload);
                    } else {
                        $pageBlock = $pageRow->blocks()->create($blockPayload);
                    }

                    $submittedBlockIds[] = $pageBlock->id;
                }

                // Delete blocks no longer in submission
                $pageRow->blocks()->whereNotIn('id', $submittedBlockIds)->delete();
            }

            // Delete rows no longer in submission
            PageRow::whereIn('id', array_diff($existingRowIds, $submittedRowIds))->delete();
        });

        return redirect()->route('vela.admin.pages.index');
    }

    public function show(Page $page)
    {
        abort_if(Gate::denies('page_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        // Open the page on the frontend rather than an admin view
        $slug = $page->slug === 'home' ? '/' : $page->slug;
        return redirect(url($slug));
    }

    public function destroy(Page $page)
    {
        abort_if(Gate::denies('page_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $page->delete();

        return back();
    }

    public function massDestroy(MassDestroyPageRequest $request)
    {
        $pages = Page::find(request('ids'));

        foreach ($pages as $page) {
            $page->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }

    public function storeCKEditorImages(Request $request)
    {
        abort_if(Gate::denies('page_create') && Gate::denies('page_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $model         = new Page();
        $model->id     = $request->input('crud_id', 0);
        $model->exists = true;
        $media         = $model->addMediaFromRequest('upload')->toMediaCollection('ck-media');

        return response()->json(['id' => $media->id, 'url' => $media->getUrl()], Response::HTTP_CREATED);
    }
}
