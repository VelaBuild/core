<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_roles';

    protected $fillable = ['title'];

    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'vela_permission_role');
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected static function newFactory(): \VelaBuild\Core\Database\Factories\RoleFactory
    {
        return \VelaBuild\Core\Database\Factories\RoleFactory::new();
    }
}
