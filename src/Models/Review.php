<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_reviews';

    protected $fillable = [
        'source',
        'place_id',
        'external_id',
        'author',
        'rating',
        'text',
        'review_date',
        'synced_at',
        'published',
    ];

    protected $dates = [
        'review_date',
        'synced_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'published' => 'boolean',
        'review_date' => 'datetime',
        'synced_at' => 'datetime',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function scopePublished($query)
    {
        return $query->where('published', true);
    }
}
