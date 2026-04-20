<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class PackageLicense extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_package_licenses';

    const TYPE_FREE = 'free';
    const TYPE_ONETIME = 'onetime';
    const TYPE_YEARLY = 'yearly';

    const VALIDATION_VALID = 'valid';
    const VALIDATION_EXPIRED = 'expired';
    const VALIDATION_INVALID = 'invalid';
    const VALIDATION_PENDING = 'pending';

    protected $fillable = [
        'installed_package_id',
        'license_key',
        'domain',
        'dev_domain',
        'type',
        'expires_at',
        'last_validated_at',
        'validation_status',
        'marketplace_purchase_id',
    ];

    protected $dates = [
        'expires_at',
        'last_validated_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_validated_at' => 'datetime',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function setLicenseKeyAttribute($value)
    {
        $this->attributes['license_key'] = Crypt::encryptString($value);
    }

    public function getLicenseKeyAttribute($value)
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function installedPackage()
    {
        return $this->belongsTo(InstalledPackage::class, 'installed_package_id');
    }
}
