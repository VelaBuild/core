<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Http\Controllers\Traits\MediaUploadingTrait;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\MediaItem;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\MediaReplacementService;

class MediaLibraryController extends Controller
{
    use MediaUploadingTrait;

    public function __construct(private AiProviderManager $aiManager) {}

    public function index(Request $request)
    {
        abort_if(Gate::denies('article_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        if ($request->ajax() && $request->wantsJson()) {
            $query = Media::query()->where('mime_type', 'like', 'image/%');

            if ($request->filled('collection')) {
                $query->where('collection_name', $request->collection);
            }

            if ($request->filled('model_type')) {
                $modelTypeMap = [
                    'Content' => 'VelaBuild\Core\Models\Content',
                    'Page' => 'VelaBuild\Core\Models\Page',
                    'Category' => 'VelaBuild\Core\Models\Category',
                    'User' => 'VelaBuild\Core\Models\VelaUser',
                    'Standalone' => 'VelaBuild\Core\Models\MediaItem',
                ];
                $fullType = $modelTypeMap[$request->model_type] ?? $request->model_type;
                $query->where('model_type', $fullType);
            }

            $query->orderBy('id', 'desc');

            $perPage = (int) $request->get('per_page', 36);
            if ($request->filled('cursor')) {
                $query->where('id', '<', (int) $request->cursor);
            }

            $items = $query->limit($perPage)->get();

            $data = $items->map(function (Media $media) {
                $original = $media->getUrl();
                $thumb = $original;
                $preview = $original;

                if ($media->hasGeneratedConversion('thumb')) {
                    $thumb = $media->getUrl('thumb');
                }
                if ($media->hasGeneratedConversion('preview')) {
                    $preview = $media->getUrl('preview');
                }

                return [
                    'id' => $media->id,
                    'file_name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'collection_name' => $media->collection_name,
                    'model_type' => $media->model_type,
                    'model_id' => $media->model_id,
                    'created_at' => $media->created_at->diffForHumans(),
                    'created_at_exact' => $media->created_at->format('jS M Y g:i a'),
                    'url' => $original,
                    'thumb' => $thumb,
                    'preview' => $preview,
                    'custom_properties' => $media->custom_properties,
                ];
            });

            $nextCursor = $items->count() === $perPage ? $items->last()->id : null;

            return response()->json([
                'data' => $data,
                'next_cursor' => $nextCursor,
            ]);
        }

        $hasAiProvider = $this->aiManager->hasImageProvider();

        return view('vela::admin.media.index', compact('hasAiProvider'));
    }

    public function show($id)
    {
        abort_if(Gate::denies('article_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $media = Media::findOrFail($id);

        $usedIn = [];

        // Resolve owner model (may be soft-deleted)
        try {
            $owner = $media->model;
            if (! $owner) {
                // Try withTrashed for soft-deletable models
                $modelClass = $media->model_type;
                if (method_exists($modelClass, 'withTrashed')) {
                    $owner = $modelClass::withTrashed()->find($media->model_id);
                }
            }
        } catch (\Exception $e) {
            $owner = null;
        }

        if ($owner) {
            $deleted = method_exists($owner, 'trashed') && $owner->trashed();

            if ($owner instanceof Content) {
                $usedIn[] = [
                    'type' => 'Content',
                    'label' => $owner->title,
                    'edit_url' => route('vela.admin.contents.edit', $owner->id),
                    'deleted' => $deleted,
                ];
            } elseif ($owner instanceof Page) {
                $usedIn[] = [
                    'type' => 'Page',
                    'label' => $owner->title,
                    'edit_url' => route('vela.admin.pages.edit', $owner->id),
                    'deleted' => $deleted,
                ];
            } elseif ($owner instanceof Category) {
                $usedIn[] = [
                    'type' => 'Category',
                    'label' => $owner->name,
                    'edit_url' => route('vela.admin.categories.edit', $owner->id),
                    'deleted' => $deleted,
                ];
            } elseif ($owner instanceof MediaItem) {
                $usedIn[] = [
                    'type' => 'Standalone',
                    'label' => $owner->title ?? 'Untitled',
                    'edit_url' => null,
                    'deleted' => $deleted,
                ];
            } elseif ($owner instanceof VelaUser) {
                $usedIn[] = [
                    'type' => 'User Avatar',
                    'label' => $owner->name,
                    'edit_url' => null,
                    'deleted' => false,
                ];
            } else {
                // PageBlock or other
                $label = class_basename($media->model_type);
                try {
                    if (method_exists($owner, 'row') && $owner->row && $owner->row->page) {
                        $label = $owner->row->page->title ?? $label;
                    }
                } catch (\Exception $e) {
                    // ignore
                }
                $usedIn[] = [
                    'type' => class_basename($media->model_type),
                    'label' => $label,
                    'edit_url' => null,
                    'deleted' => $deleted,
                ];
            }
        }

        // Also scan content bodies for this URL
        $url = $media->getUrl();
        $contentRefs = (new MediaReplacementService)->findContentReferences($url);
        foreach ($contentRefs as $ref) {
            // Avoid duplicating if owner is already Content
            if ($owner instanceof Content && $owner->id === $ref->id) {
                continue;
            }
            $usedIn[] = [
                'type' => 'Content (body)',
                'label' => $ref->title,
                'edit_url' => route('vela.admin.contents.edit', $ref->id),
                'deleted' => $ref->deleted_at !== null,
            ];
        }

        // Get title/alt_text from owner or custom_properties
        $title = null;
        $altText = null;
        if ($owner instanceof MediaItem) {
            $title = $owner->title;
            $altText = $owner->alt_text;
        } else {
            $title = $media->getCustomProperty('title');
            $altText = $media->getCustomProperty('alt_text');
        }

        $customProps = $media->custom_properties;
        $dimensions = null;
        if (! empty($customProps['width']) && ! empty($customProps['height'])) {
            $dimensions = $customProps['width'].'x'.$customProps['height'];
        } elseif (str_starts_with($media->mime_type, 'image/')) {
            try {
                $path = $media->getPath();
                if (file_exists($path)) {
                    $size = @getimagesize($path);
                    if ($size) {
                        $dimensions = $size[0].'x'.$size[1];
                    }
                }
            } catch (\Exception $e) {
                // ignore
            }
        }

        return response()->json([
            'id' => $media->id,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'collection_name' => $media->collection_name,
            'model_type' => $media->model_type,
            'model_id' => $media->model_id,
            'created_at' => $media->created_at->diffForHumans(),
            'created_at_exact' => $media->created_at->format('jS M Y g:i a'),
            'updated_at' => $media->updated_at,
            'url' => $url,
            'dimensions' => $dimensions ?: 'Unknown',
            'used_in' => $usedIn,
            'title' => $title,
            'alt_text' => $altText,
        ]);
    }

    public function store(Request $request)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'media_file' => 'required|string',
            'title' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:255',
        ]);

        $mediaItem = MediaItem::create([
            'title' => $request->title,
            'alt_text' => $request->alt_text,
            'uploaded_by' => auth('vela')->id(),
        ]);

        $mediaItem->addMedia(storage_path('tmp/uploads/'.basename($request->media_file)))
            ->toMediaCollection('media_library');

        $spatieMedia = $mediaItem->getMedia('media_library')->last();

        return response()->json([
            'success' => true,
            'media_item_id' => $mediaItem->id,
            'media_id' => $spatieMedia->id,
            'url' => $spatieMedia->getUrl(),
            'thumb' => $spatieMedia->getUrl('thumb'),
        ]);
    }

    public function destroy($id)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $media = Media::findOrFail($id);

        $owner = null;
        try {
            $owner = $media->model;
            if (! $owner) {
                $modelClass = $media->model_type;
                if (method_exists($modelClass, 'withTrashed')) {
                    $owner = $modelClass::withTrashed()->find($media->model_id);
                }
            }
        } catch (\Exception $e) {
            // ignore
        }

        if ($owner instanceof MediaItem) {
            $owner->delete();
        } else {
            $media->delete();
        }

        return response()->json([
            'success' => true,
            'deleted_id' => $id,
        ]);
    }

    public function massDestroy(Request $request)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $deletedCount = 0;

        foreach ($request->ids as $id) {
            $media = Media::find($id);
            if (! $media) {
                continue;
            }

            $owner = null;
            try {
                $owner = $media->model;
                if (! $owner) {
                    $modelClass = $media->model_type;
                    if (method_exists($modelClass, 'withTrashed')) {
                        $owner = $modelClass::withTrashed()->find($media->model_id);
                    }
                }
            } catch (\Exception $e) {
                // ignore
            }

            if ($owner instanceof MediaItem) {
                $owner->delete();
            } else {
                $media->delete();
            }

            $deletedCount++;
        }

        return response()->json([
            'success' => true,
            'deleted_count' => $deletedCount,
        ]);
    }

    public function replace(Request $request, $id)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'new_file' => 'required|string',
        ]);

        try {
            $affected = 0;
            $newUrl = null;

            DB::transaction(function () use ($request, $id, &$affected, &$newUrl) {
                $media = Media::lockForUpdate()->findOrFail($id);
                $oldUrl = $media->getUrl();
                $owner = $media->model;
                $collectionName = $media->collection_name;

                $owner->addMedia(storage_path('tmp/uploads/'.basename($request->new_file)))
                    ->toMediaCollection($collectionName);

                $newMedia = $owner->getMedia($collectionName)->last();
                $newUrl = $newMedia->getUrl();

                $media->delete();

                $affected = (new MediaReplacementService)->replaceUrls($oldUrl, $newUrl);
            });

            return response()->json([
                'success' => true,
                'new_url' => $newUrl,
                'affected_rows' => $affected,
            ]);
        } catch (\Exception $e) {
            Log::error('Media replacement failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Replacement failed',
            ], 500);
        }
    }

    public function crop(Request $request, $id)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'x' => 'required|numeric',
            'y' => 'required|numeric',
            'width' => 'required|numeric|min:1',
            'height' => 'required|numeric|min:1',
            'rotate' => 'nullable|numeric',
            'updated_at' => 'required|date',
        ]);

        $media = Media::findOrFail($id);

        if ($media->updated_at->format('Y-m-d H:i:s') !== $request->updated_at) {
            return response()->json([
                'error' => 'Image was modified by another user',
                'updated_at' => $media->updated_at,
            ], 409);
        }

        if ($request->width * $request->height > 50000000) {
            return response()->json([
                'error' => 'Image dimensions too large for crop operation',
            ], 422);
        }

        // Backup original on first crop
        if (! $media->getCustomProperty('original_file_path')) {
            $originalsDir = storage_path('app/media-originals/');
            if (! is_dir($originalsDir)) {
                mkdir($originalsDir, 0755, true);
            }
            $backupPath = $originalsDir.$media->id.'_'.$media->file_name;
            copy($media->getPath(), $backupPath);
            $media->setCustomProperty('original_file_path', $backupPath)->save();
        }

        $sourcePath = $media->getCustomProperty('original_file_path') ?: $media->getPath();

        try {
            $mime = $media->mime_type;

            if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                $source = imagecreatefromjpeg($sourcePath);
            } elseif ($mime === 'image/png') {
                $source = imagecreatefrompng($sourcePath);
            } elseif ($mime === 'image/webp') {
                $source = imagecreatefromwebp($sourcePath);
            } else {
                return response()->json(['error' => 'Unsupported image format'], 422);
            }

            if ($source === false) {
                return response()->json(['error' => 'Failed to process image'], 422);
            }

            $cropped = imagecrop($source, [
                'x' => (int) $request->x,
                'y' => (int) $request->y,
                'width' => (int) $request->width,
                'height' => (int) $request->height,
            ]);

            if ($cropped === false) {
                imagedestroy($source);

                return response()->json(['error' => 'Failed to process image'], 422);
            }

            if ($request->filled('rotate') && $request->rotate != 0) {
                $rotated = imagerotate($cropped, -(float) $request->rotate, 0);
                imagedestroy($cropped);
                $cropped = $rotated;
            }

            $destPath = $media->getPath();

            if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                imagejpeg($cropped, $destPath, 90);
            } elseif ($mime === 'image/png') {
                imagepng($cropped, $destPath);
            } elseif ($mime === 'image/webp') {
                imagewebp($cropped, $destPath, 90);
            }

            imagedestroy($source);
            imagedestroy($cropped);
        } catch (\Exception $e) {
            Log::error('Crop failed for media '.$id.': '.$e->getMessage());

            return response()->json(['error' => 'Failed to process image'], 422);
        }

        $media->setCustomProperty('dimensions', [
            'width' => (int) $request->width,
            'height' => (int) $request->height,
        ]);
        $media->generated_conversions = [];
        $media->save();

        return response()->json([
            'success' => true,
            'url' => $media->getUrl(),
            'width' => $request->width,
            'height' => $request->height,
            'updated_at' => $media->updated_at->format('Y-m-d H:i:s'),
        ]);
    }

    public function regenerateCache($id)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $media = Media::findOrFail($id);
        $media->generated_conversions = [];
        $media->save();

        return response()->json(['success' => true]);
    }

    public function clearCache($id)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $media = Media::findOrFail($id);
        $media->generated_conversions = [];
        $media->save();

        return response()->json(['success' => true]);
    }

    public function generateAi(Request $request)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'prompt' => 'required|string|max:1000',
        ]);

        if (! $this->aiManager->hasImageProvider()) {
            return response()->json([
                'success' => false,
                'message' => 'No AI image provider configured',
            ], 400);
        }

        $imageProvider = $this->aiManager->resolveImageProvider();
        $result = $imageProvider->generateImage($request->prompt);

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'Image generation failed',
            ], 500);
        }

        $filename = 'ai_'.Str::slug(Str::limit($request->prompt, 30)).'_'.time().'.png';
        $savedPath = $imageProvider->saveBase64Image($result['data'][0]['b64_json'], $filename);

        if (! $savedPath) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save generated image',
            ], 500);
        }

        $mediaItem = MediaItem::create([
            'title' => Str::limit($request->prompt, 255),
            'uploaded_by' => auth('vela')->id(),
        ]);

        $mediaItem->addMedia(Storage::disk('public')->path($savedPath))
            ->toMediaCollection('media_library');

        $spatieMedia = $mediaItem->getMedia('media_library')->last();

        return response()->json([
            'success' => true,
            'media_item_id' => $mediaItem->id,
            'media_id' => $spatieMedia->id,
            'url' => $spatieMedia->getUrl(),
            'thumb' => $spatieMedia->getUrl('thumb'),
        ]);
    }

    public function updateMeta(Request $request, $id)
    {
        abort_if(Gate::denies('article_edit'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'title' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:255',
        ]);

        $media = Media::findOrFail($id);

        $owner = null;
        try {
            $owner = $media->model;
        } catch (\Exception $e) {
            // ignore
        }

        if ($owner instanceof MediaItem) {
            $owner->update([
                'title' => $request->title,
                'alt_text' => $request->alt_text,
                'description' => $request->description,
            ]);
        }

        $media->setCustomProperty('alt_text', $request->alt_text)
            ->setCustomProperty('title', $request->title)
            ->save();

        return response()->json(['success' => true]);
    }
}
