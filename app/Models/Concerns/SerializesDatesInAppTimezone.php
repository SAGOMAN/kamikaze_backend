<?php

namespace App\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

trait SerializesDatesInAppTimezone
{
    /**
     * Serializa fechas en la zona de la app (America/Guayaquil), no en UTC.
     *
     * Un campo DATE a medianoche local no debe verse como 05:00:00Z.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)
            ->timezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
