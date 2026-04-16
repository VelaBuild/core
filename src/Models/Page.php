<?php

namespace VelaBuild\Core\Models;

use VelaBuild\Core\Database\Factories\PageFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Page extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    public $table = 'vela_pages';

    public const STATUS_SELECT = [
        'draft'     => 'Draft',
        'published' => 'Published',
        'unlisted'  => 'Unlisted',
    ];

    // Slugs that must not be used for pages (would conflict with existing routes)
    public const RESERVED_SLUGS = [
        'posts', 'categories', 'admin', 'login', 'register',
        'password', 'two-factor', 'profile', 'page-form',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $fillable = [
        'title',
        'slug',
        'locale',
        'status',
        'meta_title',
        'meta_description',
        'custom_css',
        'custom_js',
        'order_column',
        'parent_id',
    ];

    protected $appends = [
        'og_image',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(50)
            ->height(50)
            ->sharpen(10)
            ->performOnCollections('og_image');

        $this->addMediaConversion('preview')
            ->width(120)
            ->height(120)
            ->sharpen(10)
            ->performOnCollections('og_image');
    }

    public function getOgImageAttribute()
    {
        $file = $this->getMedia('og_image')->last();
        if ($file) {
            $file->url       = $file->getUrl();
            $file->thumbnail = $file->getUrl('thumb');
            $file->preview   = $file->getUrl('preview');
        }

        return $file;
    }

    public function rows()
    {
        return $this->hasMany(PageRow::class)->orderBy('order_column');
    }

    public function parent()
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Page::class, 'parent_id')->orderBy('order_column');
    }

    public function formSubmissions()
    {
        return $this->hasMany(FormSubmission::class);
    }

    protected static function newFactory()
    {
        return PageFactory::new();
    }
}
