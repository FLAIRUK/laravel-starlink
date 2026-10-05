<?php

namespace FLAIRUK\Starlink\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \FLAIRUK\Starlink\Account account(?string $name = null)
 * @method static \FLAIRUK\Starlink\Telemetry\Telemetry telemetry()
 * @method static array<int, string> accounts()
 *
 * @see \FLAIRUK\Starlink\Starlink
 */
class Starlink extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \FLAIRUK\Starlink\Starlink::class;
    }
}
