<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PageBlock extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    public $table = 'vela_page_blocks';

    public const BLOCK_TYPES = [
        'text',
        'image',
        'video',
        'html',
        'accordion',
        'contact_form',
        'carousel',
        'gallery',
        'testimonials',
        'icon_box',
        'categories_grid',
        'posts_grid',
        'hero',
        'cta',
    ];

    protected $fillable = [
        'page_row_id',
        'column_index',
        'column_width',
        'order_column',
        'type',
        'content',
        'settings',
        'background_color',
        'background_image',
    ];

    protected $casts = [
        'content'  => 'array',
        'settings' => 'array',
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
            ->performOnCollections('block_image');

        $this->addMediaConversion('preview')
            ->width(400)
            ->height(300)
            ->sharpen(10)
            ->performOnCollections('block_image');
    }

    public function row()
    {
        return $this->belongsTo(PageRow::class, 'page_row_id');
    }

    protected static function newFactory()
    {
        return \VelaBuild\Core\Database\Factories\PageBlockFactory::new();
    }
}
