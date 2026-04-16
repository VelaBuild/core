<?php

namespace VelaBuild\Core\Facades;

use Illuminate\Support\Facades\Facade;

class Vela extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \VelaBuild\Core\Vela::class;
    }
}
