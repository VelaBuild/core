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

        // Primary-locale rows only — translations are surfaced inline via
        // the `translations` column rather than as separate list rows.
        // "Primary" = top-level (parent_id IS NULL) in the site's default
        // locale; translation rows link back via parent_id.
        $sourceLocale = LaravelLocalization::getDefaultLocale();
        $targetLocales = array_values(array_diff(
            array_keys(LaravelLocalization::getSupportedLocales()),
            [$sourceLocale]
        ));

        if ($request->ajax()) {
            $query = Page::query()
                ->select(sprintf('%s.*', (new Page)->table))
                ->whereNull('parent_id')
                ->where('locale', $sourceLocale);

            $table = DataTables::of($query);

            // Pre-load translation children once so the per-row callback
            // doesn't fire N queries against the locale-children table.
            $primaryIds = $query->getQuery()->clone()->pluck('id');
            $childrenByParent = Page::whereIn('parent_id', $primaryIds)
                ->select('id', 'parent_id', 'locale')
                ->get()
                ->groupBy('parent_id');

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'page_show';
                $editGate      = 'page_edit';
                $deleteGate    = 'page_delete';
                $crudRoutePart = 'pages';
                $viewUrl       = self::viewOrPreviewUrl($row);
                $viewNewTab    = true;
                $viewIsPreview = !in_array($row->status, ['published', 'unlisted'], true);

                return view('vela::partials.datatablesActions', compact(
                    'viewGate',
                    'editGate',
                    'deleteGate',
                    'crudRoutePart',
                    'row',
                    'viewUrl',
                    'viewNewTab',
                    'viewIsPreview'
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
            $table->addColumn('translations', function ($row) use ($childrenByParent, $targetLocales) {
                if (empty($targetLocales)) {
                    return '<span class="text-muted small">—</span>';
                }
                // Map locale → translation Page id (or null if missing).
                $byLocale = ($childrenByParent[$row->id] ?? collect())->keyBy('locale');
                $out = '';
                foreach ($targetLocales as $loc) {
                    $tr = $byLocale->get($loc);
                    if ($tr) {
                        $editUrl = route('vela.admin.pages.edit', $tr->id);
                        $out .= '<a href="' . e($editUrl) . '" class="badge badge-success mr-1" title="' . e(__('Edit :loc translation', ['loc' => $loc])) . '">'
                              . e(strtoupper($loc)) . '</a>';
                    } else {
                        $out .= '<span class="badge badge-light text-muted mr-1" title="' . e(__('Missing :loc translation', ['loc' => $loc])) . '">'
                              . e(strtoupper($loc)) . '</span>';
                    }
                }
                return $out;
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

            $table->rawColumns(['actions', 'placeholder', 'status', 'translations']);

            return $table->make(true);
        }

        return view('vela::admin.pages.index', [
            'sourceLocale'  => $sourceLocale,
            'targetLocales' => $targetLocales,
        ]);
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
        // Validation has already accepted this slug, which means no live page
        // holds it. A deleted one still might, and the table's unique index
        // counts those — so take the address off it first.
        Page::releaseSlugFromTrash(
            (string) $request->input('slug'),
            (string) $request->input('locale', 'en')
        );

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
                    'padding'          => $this->cleanSpacing($rowData['padding'] ?? null),
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
                        'padding'          => $this->cleanSpacing($blockData['padding'] ?? null),
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
        Page::releaseSlugFromTrash(
            (string) $request->input('slug'),
            (string) $request->input('locale', $page->locale),
            $page->id
        );

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

        // What arrives here is the whole page: any row missing from it is
        // deleted below. A request that never mentions rows at all is not the
        // same statement as "the page is now empty", and reading it that way
        // turns a caller that forgot the field into a wipe of every block.
        $rowsSubmitted = $request->has('rows');
        $rowsData      = $rowsSubmitted ? (json_decode($request->input('rows'), true) ?? []) : [];

        // An in-place save keeps the same editor open, so the browser still
        // holds the placeholder ids it invented for rows and blocks that did
        // not exist yet. Handing back what each one became lets it save again
        // without the server reading those placeholders as another new row.
        $idMap = ['rows' => [], 'blocks' => []];

        if ($rowsSubmitted) {
            DB::transaction(function () use ($page, $rowsData, &$idMap) {
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
                        'padding'          => $this->cleanSpacing($rowData['padding'] ?? null),
                        'width'            => in_array($rowData['width'] ?? null, ['full', 'contained'], true) ? $rowData['width'] : 'contained',
                        'order_column'     => $rowData['order'] ?? $rowOrder,
                    ];

                    if ($rowId && is_numeric($rowId) && in_array((int) $rowId, $existingRowIds)) {
                        $pageRow = PageRow::find((int) $rowId);
                        $pageRow->update($rowPayload);
                    } else {
                        $pageRow = PageRow::create(array_merge(['page_id' => $page->id], $rowPayload));
                    }

                    $submittedRowIds[] = $pageRow->id;
                    if ($rowId !== null) {
                        $idMap['rows'][(string) $rowId] = $pageRow->id;
                    }

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
                            'padding'          => $this->cleanSpacing($blockData['padding'] ?? null),
                        ];

                        if ($blockId && is_numeric($blockId) && in_array((int) $blockId, $existingBlockIds)) {
                            $pageBlock = PageBlock::find((int) $blockId);
                            $pageBlock->update($blockPayload);
                        } else {
                            $pageBlock = $pageRow->blocks()->create($blockPayload);
                        }

                        $submittedBlockIds[] = $pageBlock->id;
                        if ($blockId !== null) {
                            $idMap['blocks'][(string) $blockId] = $pageBlock->id;
                        }
                    }

                    // Delete blocks no longer in submission
                    $pageRow->blocks()->whereNotIn('id', $submittedBlockIds)->delete();
                }

                // Delete rows no longer in submission
                PageRow::whereIn('id', array_diff($existingRowIds, $submittedRowIds))->delete();
            });
        }

        // The content lives in rows and blocks, so rearranging the whole page
        // without touching a field left "last updated" pointing at whenever the
        // title was last edited — days earlier, on a page just rewritten.
        $page->touch();

        if ($request->wantsJson()) {
            $page = $page->fresh();

            return response()->json([
                'saved'      => true,
                'id_map'     => $idMap,
                'status'     => $page->status,
                'updated_at' => $page->updated_at->diffForHumans(),
                'view_url'   => self::viewOrPreviewUrl($page),
                'is_public'  => in_array($page->status, ['published', 'unlisted'], true),
                // The next save re-sends this so an unchanged image is left
                // alone instead of being deleted and copied again each time.
                'og_image'   => $page->og_image ? $page->og_image->file_name : null,
            ]);
        }

        return redirect()->route('vela.admin.pages.index');
    }

    public function show(Page $page)
    {
        abort_if(Gate::denies('page_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        // Open the page on the frontend rather than an admin view
        return redirect(self::viewOrPreviewUrl($page));
    }

    /**
     * Public URL for pages a visitor can reach, admin preview URL otherwise.
     * Drafts (and any future non-public status) 404 on the public route, so
     * linking there would just hand the editor a dead end.
     */
    public static function viewOrPreviewUrl(Page $page): string
    {
        if (in_array($page->status, ['published', 'unlisted'], true)) {
            return url($page->slug === 'home' ? '/' : $page->slug);
        }

        return route('vela.admin.pages.preview', $page->id);
    }

    /**
     * Render a page through the public template regardless of its status.
     *
     * Draft pages 404 on the public route by design, so editors had no way to
     * see their work before publishing. This renders the same template the
     * visitor would get, but behind the admin gate and marked noindex so it
     * never leaks into search or a shared cache.
     */
    public function preview(Page $page)
    {
        abort_if(Gate::denies('page_edit') && Gate::denies('page_show'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $page->load('rows.blocks');

        return response()
            ->view(vela_template_view('page'), compact('page'))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store, max-age=0');
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

    /**
     * Keep a padding value to something that can only ever be a padding value.
     *
     * It is written straight into a style attribute, and nothing checked it on
     * the way in: "0;background:url(...)" was a perfectly acceptable answer to
     * a box labelled "Padding". The admin is trusted, but a field that accepts
     * arbitrary CSS is a field that will one day be reached by something that
     * is not the admin.
     */
    protected function cleanSpacing($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $lengths = preg_split('/\s+/', $value);
        if (count($lengths) > 4) {
            return null;
        }

        foreach ($lengths as $length) {
            if (!preg_match('/^(0|\d{1,4}(\.\d{1,2})?(px|rem|em|%|vh|vw))$/i', $length)) {
                return null;
            }
        }

        return implode(' ', $lengths);
    }
}
