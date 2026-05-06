<?php

namespace VelaBuild\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    public $table = 'vela_menus';

    protected $fillable = [
        'slot',
        'label',
        'auto_add_pages',
    ];

    protected $casts = [
        'auto_add_pages' => 'bool',
    ];

    public function items()
    {
        return $this->hasMany(MenuItem::class)->orderBy('order_column');
    }

    public static function forSlot(string $slot): ?self
    {
        return static::where('slot', $slot)->first();
    }
}
