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

    /**
     * The role given to accounts that sign themselves up, and only to them, so
     * widening the User role for editors never widens what a stranger gets.
     */
    public const GUEST_TITLE = 'Guest';

    /**
     * Everything the Guest role starts with: its own profile. The dashboard
     * needs no permission.
     */
    public const GUEST_PERMISSIONS = [
        'profile_password_edit',
    ];

    /**
     * The Guest role, made on first use so sites installed before it existed
     * get one too. Its permissions are set only when it is made, so whatever
     * an admin changes on it afterwards stands.
     */
    public static function guest(): self
    {
        $role = static::firstOrCreate(['title' => self::GUEST_TITLE]);

        if ($role->wasRecentlyCreated) {
            $role->permissions()->sync(
                Permission::whereIn('title', self::GUEST_PERMISSIONS)->pluck('id')->all()
            );
        }

        return $role;
    }

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
