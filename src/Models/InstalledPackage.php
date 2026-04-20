<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstalledPackage extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_installed_packages';

    const STATUS_ACTIVE = 'active';
    const STATUS_DISABLED = 'disabled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'vendor_name',
        'package_name',
        'composer_name',
        'version',
        'status',
        'installed_at',
    ];

    protected $dates = [
        'installed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function license()
    {
        return $this->hasOne(PackageLicense::class, 'installed_package_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
