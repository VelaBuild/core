<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\CsvImportTrait;
use VelaBuild\Core\Http\Controllers\Traits\MediaUploadingTrait;
use VelaBuild\Core\Http\Requests\MassDestroyContentRequest;
use VelaBuild\Core\Http\Requests\StoreContentRequest;
use VelaBuild\Core\Http\Requests\UpdateContentRequest;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Translation;
use VelaBuild\Core\Models\VelaUser;
use Gate;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use Yajra\DataTables\Facades\DataTables;

class ArticleController extends Controller
{
    use MediaUploadingTrait, CsvImportTrait;

    public function index(Request $request)
    {
        abort_if(Gate::denies('article_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax()) {
            $query = Content::with(['author', 'categories'])->select(sprintf('%s.*', (new Content)->table));

            // Apply filters
            if ($request->has('category_filter') && $request->category_filter) {
                $query->whereHas('categories', function($q) use ($request) {
                    $q->where('vela_categories.id', $request->category_filter);
                });
            }

            if ($request->has('status_filter') && $request->status_filter) {
                $query->where('status', $request->status_filter);
            }

            $table = Datatables::of($query);

            $table->addColumn('placeholder', '&nbsp;');
            $table->addColumn('actions', '&nbsp;');

            $table->editColumn('actions', function ($row) {
                $viewGate      = 'article_show';
                $editGate      = 'article_edit';
                $deleteGate    = 'article_delete';
                $crudRoutePart = 'contents';

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
            $table->editColumn('title', function ($row) {
                return $row->title ? $row->title : '';
            });
            $table->editColumn('slug', function ($row) {
                return $row->slug ? $row->slug : '';
            });

            $table->editColumn('description', function ($row) {
                return $row->description ? $row->description : '';
            });
            $table->editColumn('main_image', function ($row) {
                if ($photo = $row->main_image) {
                    return sprintf(
                        '<a href="%s" target="_blank"><img src="%s" width="50px" height="50px"></a>',
                        $photo->url,
                        $photo->thumbnail
                    );
                }

                return '';
            });
            $table->addColumn('author_name', function ($row) {
                return $row->author ? $row->author->name : '';
            });

            $table->addColumn('categories', function ($row) {
                if (!$row->categories || $row->categories->isEmpty()) {
                    return '';
                }

                $categoryNames = $row->categories->pluck('name')->toArray();
                return implode(', ', $categoryNames);
            });

            $table->editColumn('status', function ($row) {
                $status = $row->status ? __('vela::global.status_' . $row->status) : '';

                // Calculate pending images
                $pendingImages = $this->calculatePendingImages($row);

                if ($pendingImages > 0) {
                    $status .= ' <span class="badge badge-warning">' . __('vela::global.images_pending', ['count' => $pendingImages]) . '</span>';
                }

                // Calculate translation completion percentage
                $translationPercentage = $this->calculateTranslationPercentage($row);

                if ($translationPercentage < 100) {
                    $status .= ' <span class="badge badge-info">' . __('vela::global.translated_percent', ['percent' => $translationPercentage]) . '</span>';
                }

                return $status;
            });

            $table->rawColumns(['actions', 'placeholder', 'main_image', 'status', 'author', 'categories']);

            return $table->make(true);
        }

        return view('vela::admin.contents.index');
    }

    public function create()
    {
        abort_if(Gate::denies('article_create'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $authors = VelaUser::pluck('name', 'id')->prepend(trans('vela::global.pleaseSelect'), '');
        $categories = Category::pluck('name', 'id');

        return view('vela::admin.contents.create', compact('authors', 'categories'));
    }

    public function store(StoreContentRequest $request)
    {
        $content = Content::create($request->all());

        // Sync categories
        $content->categories()->sync($request->input('categories', []));

        if ($request->input('main_image', false)) {
            $content->addMedia(storage_path('tmp/uploads/' . basename($request->input('main_image'))))->toMediaCollection('main_image');
        }

        foreach ($request->input('gallery', []) as $file) {
            $content->addMedia(storage_path('tmp/uploads/' . basename($file)))->toMediaCollection('gallery');
        }

        if ($media = $request->input('ck-media', false)) {
            Media::whereIn('id', $media)->update(['model_id' => $content->id]);
        }

        // Save translations if provided
        $primary = config('vela.primary_language');
        $trans = $request->input('trans', []);

        foreach ($trans as $lang => $fields) {
            if ($lang === $primary) { continue; }

            foreach (['title','description','content'] as $field) {
                if (!array_key_exists($field, $fields)) { continue; }
                $value = $fields[$field];

                Translation::updateOrCreate(
                    [
                        'lang_code' => $lang,
                        'model_type' => 'Content',
                        'model_key' => $content->id . '_' . $field,
                    ],
                    [
                        'translation' => $value,
                    ]
                );
            }
        }

        return redirect()->route('vela.admin.contents.index');
    }

    public function edit(Content $content)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $authors = VelaUser::pluck('name', 'id')->prepend(trans('vela::global.pleaseSelect'), '');
        $categories = Category::pluck('name', 'id');

        $content->load(['author', 'categories', 'content_images']);

        // Load translations for prefill
        $primary = config('vela.primary_language');
        $translations = [];
        $rows = Translation::where('model_type', 'Content')
            ->where(function($q) use ($content) {
                $q->where('model_key', $content->id . '_title')
                  ->orWhere('model_key', $content->id . '_description')
                  ->orWhere('model_key', $content->id . '_content');
            })->get();
        foreach ($rows as $row) {
            $field = str_replace($content->id . '_', '', $row->model_key);
            $translations[$row->lang_code][$field] = $row->translation;
        }

        // Analyze which content_images are used in the article content
        $usedImageUrls = [];
        $contentData = json_decode($content->content, true);
        if ($contentData && isset($contentData['blocks'])) {
            foreach ($contentData['blocks'] as $block) {
                if ($block['type'] === 'image' && isset($block['data']['file']['url'])) {
                    $usedImageUrls[] = $block['data']['file']['url'];
                }
            }
        }

        // Split content_images into used and unused
        $articleImages = [];
        $otherImages = [];

        foreach ($content->content_images as $image) {
            $imageUrl = $image->getUrl();
            if (in_array($imageUrl, $usedImageUrls)) {
                $articleImages[] = $image;
            } else {
                $otherImages[] = $image;
            }
        }

        return view('vela::admin.contents.edit', compact('authors', 'categories', 'content', 'translations', 'articleImages', 'otherImages'));
    }

    public function update(UpdateContentRequest $request, Content $content)
    {
        $content->update($request->all());

        // Sync categories
        $content->categories()->sync($request->input('categories', []));

        if ($request->input('main_image', false)) {
            if (! $content->main_image || $request->input('main_image') !== $content->main_image->file_name) {
                if ($content->main_image) {
                    $content->main_image->delete();
                }
                $content->addMedia(storage_path('tmp/uploads/' . basename($request->input('main_image'))))->toMediaCollection('main_image');
            }
        } elseif ($content->main_image) {
            $content->main_image->delete();
        }

        if (count($content->gallery) > 0) {
            foreach ($content->gallery as $media) {
                if (! in_array($media->file_name, $request->input('gallery', []))) {
                    $media->delete();
                }
            }
        }
        $media = $content->gallery->pluck('file_name')->toArray();
        foreach ($request->input('gallery', []) as $file) {
            if (count($media) === 0 || ! in_array($file, $media)) {
                $content->addMedia(storage_path('tmp/uploads/' . basename($file)))->toMediaCollection('gallery');
            }
        }

        // Save translations if provided
        $primary = config('vela.primary_language');
        $trans = $request->input('trans', []);

        foreach ($trans as $lang => $fields) {
            if ($lang === $primary) { continue; }

            foreach (['title','description','content'] as $field) {
                if (!array_key_exists($field, $fields)) { continue; }
                $value = $fields[$field];

                Translation::updateOrCreate(
                    [
                        'lang_code' => $lang,
                        'model_type' => 'Content',
                        'model_key' => $content->id . '_' . $field,
                    ],
                    [
                        'translation' => $value,
                    ]
                );
            }
        }

        return redirect()->route('vela.admin.contents.index');
    }

    public function show(Content $content)
    {
        return redirect(route('vela.public.posts.show', $content->slug));
    }

    public function destroy(Content $content)
    {
        abort_if(Gate::denies('article_delete'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $content->delete();

        return back();
    }

    public function massDestroy(MassDestroyContentRequest $request)
    {
        $contents = Content::find(request('ids'));

        foreach ($contents as $content) {
            $content->delete();
        }

        return response(null, Response::HTTP_NO_CONTENT);
    }

    public function massPublish(Request $request)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:vela_articles,id'
        ]);

        $contentIds = $request->input('ids');
        $contents = Content::whereIn('id', $contentIds)->get();

        if ($contents->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => __('vela::global.no_valid_content')
            ], 400);
        }

        try {
            $publishedCount = 0;

            foreach ($contents as $content) {
                $content->update([
                    'status' => 'published',
                    'published_at' => now()
                ]);
                $publishedCount++;
            }

            return response()->json([
                'success' => true,
                'message' => __('vela::global.published_count_success', ['count' => $publishedCount]),
                'count' => $publishedCount
            ]);

        } catch (\Exception $e) {
            \Log::error('Bulk publish failed', [
                'error' => $e->getMessage(),
                'content_ids' => $contentIds
            ]);

            return response()->json([
                'success' => false,
                'message' => __('vela::global.publish_error_occurred')
            ], 500);
        }
    }

    public function storeCKEditorImages(Request $request)
    {
        abort_if(Gate::denies('article_create') && Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $model         = new Content();
        $model->id     = $request->input('crud_id', 0);
        $model->exists = true;
        $media         = $model->addMediaFromRequest('upload')->toMediaCollection('ck-media');

        return response()->json(['id' => $media->id, 'url' => $media->getUrl()], Response::HTTP_CREATED);
    }

    /**
     * Calculate the number of pending images for a content item
     */
    private function calculatePendingImages($content)
    {
        $pendingCount = 0;

        // Check if main image is missing
        if (!$content->main_image) {
            $pendingCount++;
        }

        // Check for [IMAGE] tags in content
        if ($content->content) {
            $contentData = json_decode($content->content, true);
            if ($contentData && isset($contentData['blocks'])) {
                foreach ($contentData['blocks'] as $block) {
                    if ($block['type'] === 'paragraph' && isset($block['data']['text'])) {
                        // Check for [IMAGE] tags in paragraph text
                        $pattern = '/\[IMAGE\s+topic="[^"]+"\s+alt="[^"]+"\]/i';
                        if (preg_match_all($pattern, $block['data']['text'], $matches)) {
                            $pendingCount += count($matches[0]);
                        }
                    } elseif ($block['type'] === 'image' && isset($block['data']['file']['url']) && empty($block['data']['file']['url'])) {
                        // Check for empty image blocks (placeholder images)
                        $pendingCount++;
                    }
                }
            } else {
                // Fallback: parse raw [IMAGE] tags from plain text
                $pattern = '/\[IMAGE\s+topic="[^"]+"\s+alt="[^"]+"\]/i';
                if (preg_match_all($pattern, $content->content, $matches)) {
                    $pendingCount += count($matches[0]);
                }
            }
        }

        return $pendingCount;
    }

    /**
     * Calculate the translation completion percentage for a content item
     */
    private function calculateTranslationPercentage($content)
    {
        // Get supported languages (excluding English as it's the default)
        $supportedLanguages = array_keys(
            array_filter(
                config('vela.available_languages', []),
                fn($lang) => $lang !== config('vela.primary_language', 'en'),
                ARRAY_FILTER_USE_KEY
            )
        );
        $totalLanguages = count($supportedLanguages);
        $translatedLanguages = 0;

        foreach ($supportedLanguages as $locale) {
            // Check if title is translated
            $titleTranslation = Translation::where('model_type', 'Content')
                ->where('model_key', $content->id . '_title')
                ->where('lang_code', $locale)
                ->whereNotNull('translation')
                ->where('translation', '!=', '')
                ->first();

            // Check if description is translated
            $descriptionTranslation = Translation::where('model_type', 'Content')
                ->where('model_key', $content->id . '_description')
                ->where('lang_code', $locale)
                ->whereNotNull('translation')
                ->where('translation', '!=', '')
                ->first();

            // Consider a language translated if at least title and description are translated
            if ($titleTranslation && $descriptionTranslation) {
                $translatedLanguages++;
            }
        }

        if ($totalLanguages == 0) {
            return 100; // No languages to translate
        }

        return round(($translatedLanguages / $totalLanguages) * 100);
    }

    public function removeContentImage(Request $request, Content $content)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'image_id' => 'required|integer'
        ]);

        try {
            $image = $content->content_images()->find($request->input('image_id'));

            if (!$image) {
                return response()->json([
                    'success' => false,
                    'message' => __('vela::global.image_not_found')
                ], 404);
            }

            // Delete the media file
            $image->delete();

            return response()->json([
                'success' => true,
                'message' => __('vela::global.image_removed_success')
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to remove content image', [
                'content_id' => $content->id,
                'image_id' => $request->input('image_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => __('vela::global.image_remove_error')
            ], 500);
        }
    }
}
