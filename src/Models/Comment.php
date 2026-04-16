<?php

namespace VelaBuild\Core\Models;

use VelaBuild\Core\Database\Factories\CommentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_comments';

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public const STATUS_SELECT = [
        'visible' => 'Visible',
        'report'  => 'Reported',
        'hidden'  => 'Hidden',
    ];

    protected $fillable = [
        'user_id',
        'comment',
        'status',
        'useragent',
        'ipaddress',
        'parent',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function user()
    {
        return $this->belongsTo(VelaUser::class, 'user_id');
    }

    protected static function newFactory()
    {
        return CommentFactory::new();
    }
}
