<?php

namespace VelaBuild\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageRow extends Model
{
    use HasFactory;

    public $table = 'vela_page_rows';

    protected $fillable = [
        'page_id',
        'name',
        'css_class',
        'background_color',
        'background_image',
        'text_color',
        'text_alignment',
        'padding',
        'width',
        'order_column',
    ];

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function page()
    {
        return $this->belongsTo(Page::class);
    }

    public function blocks()
    {
        return $this->hasMany(PageBlock::class)->orderBy('order_column');
    }

    protected static function newFactory()
    {
        return \VelaBuild\Core\Database\Factories\PageRowFactory::new();
    }
}
