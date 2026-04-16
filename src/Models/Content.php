<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Content extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    public $table = 'vela_articles';

    protected $appends = [
        'main_image',
        'gallery',
        'content_images',
    ];


    protected $dates = [
        'written_at',
        'approved_at',
        'published_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'written_at'  => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'deleted_at'  => 'datetime',
    ];

    public const STATUS_SELECT = [
        'planned'   => 'Planned',
        'draft'     => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
    ];

    protected $fillable = [
        'title',
        'slug',

        'description',
        'keyword',
        'content',
        'author_id',
        'status',
        'written_at',
        'approved_at',
        'published_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($content) {
            if (empty($content->slug) && !empty($content->title)) {
                $content->slug = \Illuminate\Support\Str::slug($content->title);
                // Ensure uniqueness
                $original = $content->slug;
                $count = 1;
                while (static::withTrashed()->where('slug', $content->slug)->exists()) {
                    $content->slug = $original . '-' . $count++;
                }
            }
        });
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(50)
            ->height(50)
            ->sharpen(10)
            ->performOnCollections('main_image', 'gallery', 'content_images');

        $this->addMediaConversion('preview')
            ->width(120)
            ->height(120)
            ->sharpen(10)
            ->performOnCollections('main_image', 'gallery', 'content_images');
    }

    public function getMainImageAttribute()
    {
        $file = $this->getMedia('main_image')->last();
        if ($file) {
            $file->url       = $file->getUrl();
            $file->thumbnail = $file->getUrl('thumb');
            $file->preview   = $file->getUrl('preview');
        }

        return $file;
    }

    public function getGalleryAttribute()
    {
        $files = $this->getMedia('gallery');
        $files->each(function ($item) {
            $item->url       = $item->getUrl();
            $item->thumbnail = $item->getUrl('thumb');
            $item->preview   = $item->getUrl('preview');
        });

        return $files;
    }

    public function getContentImagesAttribute()
    {
        $files = $this->getMedia('content_images');
        $files->each(function ($item) {
            $item->url       = $item->getUrl();
            $item->thumbnail = $item->getUrl('thumb');
            $item->preview   = $item->getUrl('preview');
        });

        return $files;
    }

    public function author()
    {
        return $this->belongsTo(VelaUser::class, 'author_id');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'vela_article_category', 'article_id', 'category_id');
    }

    public function content_images()
    {
        return $this->morphMany(Media::class, 'model')->where('collection_name', 'content_images');
    }

    public function getTranslatedTitleAttribute()
    {
        $locale = app()->getLocale();

        $translation = Translation::where('model_type', 'Content')
            ->where('model_key', $this->id . '_title')
            ->where('lang_code', $locale)
            ->first();

        if ($translation && $translation->translation) {
            return $translation->translation;
        }

        return $this->title;
    }

    public function getTranslatedDescriptionAttribute()
    {
        $locale = app()->getLocale();

        $translation = Translation::where('model_type', 'Content')
            ->where('model_key', $this->id . '_description')
            ->where('lang_code', $locale)
            ->first();

        if ($translation && $translation->translation) {
            return $translation->translation;
        }

        return $this->description;
    }

    public function getTranslatedContentAttribute()
    {
        $locale = app()->getLocale();

        $translation = Translation::where('model_type', 'Content')
            ->where('model_key', $this->id . '_content')
            ->where('lang_code', $locale)
            ->first();

        if ($translation && $translation->translation) {
            $translatedContent = $translation->translation;
            $translatedContent = $this->translateImageAltTags($translatedContent, $locale);

            return $translatedContent;
        }

        return $this->content;
    }

    private function translateImageAltTags($content, $locale)
    {
        $contentData = json_decode($content, true);
        if (!$contentData || !isset($contentData['blocks'])) {
            return $content;
        }

        foreach ($contentData['blocks'] as &$block) {
            if ($block['type'] === 'image' && isset($block['data']['caption'])) {
                $block['data']['caption'] = $this->translateText($block['data']['caption'], $locale);
            }
        }

        return json_encode($contentData);
    }

    private function translateText($text, $locale)
    {
        $translation = Translation::where('model_type', 'Content')
            ->where('model_key', $this->id . '_image_alt_' . md5($text))
            ->where('lang_code', $locale)
            ->first();

        if ($translation && $translation->translation) {
            return $translation->translation;
        }

        return $text;
    }

    protected static function newFactory(): \VelaBuild\Core\Database\Factories\ContentFactory
    {
        return \VelaBuild\Core\Database\Factories\ContentFactory::new();
    }
}
