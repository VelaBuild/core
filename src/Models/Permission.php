<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_permissions';

    protected $fillable = ['title'];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected static function newFactory(): \VelaBuild\Core\Database\Factories\PermissionFactory
    {
        return \VelaBuild\Core\Database\Factories\PermissionFactory::new();
    }
}
