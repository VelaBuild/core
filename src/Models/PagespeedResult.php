<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PagespeedResult extends Model
{
    use SoftDeletes, HasFactory;

    public $table = 'vela_pagespeed_results';

    protected $fillable = [
        'url',
        'performance_score',
        'accessibility_score',
        'seo_score',
        'best_practices_score',
        'raw_data',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'performance_score' => 'integer',
        'accessibility_score' => 'integer',
        'seo_score' => 'integer',
        'best_practices_score' => 'integer',
        'raw_data' => 'array',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
