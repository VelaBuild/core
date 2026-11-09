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

    // Background operations (RunPackageOperation).
    const STATUS_INSTALLING = 'installing';
    const STATUS_UPDATING = 'updating';
    const STATUS_REMOVING = 'removing';
    const STATUS_REPAIRING = 'repairing';
    const STATUS_FAILED = 'failed';

    /** A package in one of these is mid-operation: no other action until it ends. */
    const BUSY = [self::STATUS_INSTALLING, self::STATUS_UPDATING, self::STATUS_REMOVING, self::STATUS_REPAIRING];

    protected $fillable = [
        'vendor_name',
        'package_name',
        'composer_name',
        'version',
        'status',
        'installed_at',
        'operation_log',
        'operation_error',
        'operation_started_at',
        'operation_finished_at',
    ];

    protected $dates = [
        'installed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
        'operation_started_at' => 'datetime',
        'operation_finished_at' => 'datetime',
    ];

    public function isBusy(): bool
    {
        return in_array($this->status, self::BUSY, true);
    }

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
