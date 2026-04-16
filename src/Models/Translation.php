<?php

namespace VelaBuild\Core\Models;

use VelaBuild\Core\Database\Factories\TranslationFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Translation extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_translations';

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $fillable = [
        'lang_code',
        'model_type',
        'model_key',
        'translation',
        'notes',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected static function newFactory()
    {
        return TranslationFactory::new();
    }
}
