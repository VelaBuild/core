<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaItem extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia, HasFactory;

    public $table = 'vela_media_items';

    protected $fillable = ['title', 'alt_text', 'description', 'uploaded_by'];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

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
            ->performOnCollections('media_library');

        $this->addMediaConversion('preview')
            ->width(120)
            ->height(120)
            ->sharpen(10)
            ->performOnCollections('media_library');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(VelaUser::class, 'uploaded_by');
    }

    public function getImageAttribute()
    {
        $file = $this->getMedia('media_library')->first();
        if ($file) {
            $file->url       = $file->getUrl();
            $file->thumbnail = $file->getUrl('thumb');
            $file->preview   = $file->getUrl('preview');
        }

        return $file;
    }

    protected static function newFactory(): \VelaBuild\Core\Database\Factories\MediaItemFactory
    {
        return \VelaBuild\Core\Database\Factories\MediaItemFactory::new();
    }
}
