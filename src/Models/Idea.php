<?php

namespace VelaBuild\Core\Models;

use VelaBuild\Core\Database\Factories\IdeaFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Idea extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_ideas';

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $fillable = [
        'name',
        'details',
        'keyword',
        'status',
        'category_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public const STATUS_SELECT = [
        'new'     => 'New',
        'planned' => 'Planned',
        'created' => 'Created',
        'reject'  => 'Rejected',
    ];

    public const STATUS_FILTERS = [
        'open'    => 'Open (New & Planned)',
        'new'     => 'New',
        'planned' => 'Planned',
        'created' => 'Created',
        'reject'  => 'Rejected',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    protected static function newFactory()
    {
        return IdeaFactory::new();
    }
}
