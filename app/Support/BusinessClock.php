<?php

namespace App\Support;

use Carbon\Carbon;

final class BusinessClock
{
    /** Zona de negocio; debe coincidir con config('app.timezone'). */
    public const TIMEZONE = 'America/Guayaquil';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function todayDate(): string
    {
        return self::now()->toDateString();
    }
}
