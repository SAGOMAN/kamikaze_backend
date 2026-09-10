<?php

namespace App\Support;

use Carbon\Carbon;

final class BusinessClock
{
    public const TIMEZONE = 'America/Mexico_City';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function todayDate(): string
    {
        return self::now()->toDateString();
    }
}
