<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormSubmission extends Model
{
    use HasFactory;

    public $table = 'vela_form_submissions';

    protected $fillable = [
        'page_id',
        'block_id',
        'data',
        'ip_address',
        'user_agent',
        'is_read',
    ];

    protected $casts = [
        'data'    => 'array',
        'is_read' => 'boolean',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function page()
    {
        return $this->belongsTo(Page::class);
    }

    protected static function newFactory()
    {
        return \VelaBuild\Core\Database\Factories\FormSubmissionFactory::new();
    }
}
