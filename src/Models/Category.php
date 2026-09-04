<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Category extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    public $table = 'vela_categories';

    protected $appends = [
        'image',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $fillable = [
        'name',
        'icon',
        'order_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

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
            ->performOnCollections('image');

        $this->addMediaConversion('preview')
            ->width(120)
            ->height(120)
            ->sharpen(10)
            ->performOnCollections('image');
    }

    public function getImageAttribute()
    {
        $file = $this->getMedia('image')->last();
        if ($file) {
            $file->url       = $file->getUrl();
            $file->thumbnail = $file->getUrl('thumb');
            $file->preview   = $file->getUrl('preview');
        }

        return $file;
    }

    public function contents()
    {
        return $this->belongsToMany(Content::class, 'vela_article_category', 'category_id', 'article_id');
    }

    /**
     * The address this category is reached at.
     *
     * A category has no slug column: CategoryController::show() finds one by
     * slugifying every name and comparing, so the slug is a reading of the
     * name rather than a stored value. Everything else assumed the column was
     * there — `MenuItem::resolveUrl()` built
     * `route('categories.show', $category->slug)` with null, which throws and
     * is caught, so **every category menu item resolved to "#"**. Reading it
     * here fixes that and anything else that asks.
     *
     * Not in $appends: this is for code that asks, not a new field in every
     * serialised category.
     */
    public function getSlugAttribute(): string
    {
        return \Illuminate\Support\Str::slug((string) $this->name);
    }

    public function getTranslatedNameAttribute()
    {
        $locale = app()->getLocale();

        $translation = Translation::where('model_type', 'Category')
            ->where('model_key', $this->id . '_name')
            ->where('lang_code', $locale)
            ->first();

        if ($translation && $translation->translation) {
            return $translation->translation;
        }

        return $this->name;
    }

    public function getTranslatedDescriptionAttribute()
    {
        $locale = app()->getLocale();

        $translation = Translation::where('model_type', 'Category')
            ->where('model_key', $this->id . '_description')
            ->where('lang_code', $locale)
            ->first();

        if ($translation && $translation->translation) {
            return $translation->translation;
        }

        return $this->description;
    }

    protected static function newFactory(): \VelaBuild\Core\Database\Factories\CategoryFactory
    {
        return \VelaBuild\Core\Database\Factories\CategoryFactory::new();
    }
}
