<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\BusinessClock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassSchedule extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'instructor_id',
        'branch_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function occursAt(Carbon $at): bool
    {
        $local = $at->copy()->timezone(BusinessClock::TIMEZONE);

        if ((int) $this->day_of_week !== (int) $local->dayOfWeek) {
            return false;
        }

        $now = $local->format('H:i:s');

        return $now >= $this->normalizedTime($this->start_time)
            && $now < $this->normalizedTime($this->end_time);
    }

    public function overlapsWith(self $other): bool
    {
        return $this->normalizedTime($this->start_time) < $this->normalizedTime($other->end_time)
            && $this->normalizedTime($other->start_time) < $this->normalizedTime($this->end_time);
    }

    public function normalizedTime(?string $time): string
    {
        if ($time === null || $time === '') {
            return '00:00:00';
        }

        $time = substr($time, 0, 8);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time.':00';
        }

        return $time;
    }
}
