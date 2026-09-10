<?php

namespace Tests\Unit;

use App\Models\ClassSchedule;
use App\Support\BusinessClock;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function occurs_at_current_class_window(): void
    {
        $schedule = new ClassSchedule([
            'day_of_week' => 4,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-30 10:30:00', BusinessClock::TIMEZONE));

        $this->assertTrue($schedule->occursAt(BusinessClock::now()));
    }

    #[Test]
    public function does_not_occur_before_start_or_at_end(): void
    {
        $schedule = new ClassSchedule([
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-30 09:59:59', BusinessClock::TIMEZONE));
        $this->assertFalse($schedule->occursAt(BusinessClock::now()));

        Carbon::setTestNow(Carbon::parse('2026-07-30 11:00:00', BusinessClock::TIMEZONE));
        $this->assertFalse($schedule->occursAt(BusinessClock::now()));
    }

    #[Test]
    public function does_not_occur_on_other_weekday(): void
    {
        $schedule = new ClassSchedule([
            'day_of_week' => 4,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-31 10:30:00', BusinessClock::TIMEZONE));

        $this->assertFalse($schedule->occursAt(BusinessClock::now()));
    }

    #[Test]
    public function overlapping_schedules_conflict_across_branches(): void
    {
        $centro = new ClassSchedule([
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);
        $norte = new ClassSchedule([
            'start_time' => '10:30:00',
            'end_time' => '12:00:00',
        ]);

        $this->assertTrue($centro->overlapsWith($norte));
        $this->assertTrue($norte->overlapsWith($centro));
    }

    #[Test]
    public function consecutive_schedules_do_not_overlap(): void
    {
        $morning = new ClassSchedule([
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);
        $next = new ClassSchedule([
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->assertFalse($morning->overlapsWith($next));
        $this->assertFalse($next->overlapsWith($morning));
    }

    #[Test]
    public function normalized_time_pads_seconds(): void
    {
        $schedule = new ClassSchedule;

        $this->assertSame('10:00:00', $schedule->normalizedTime('10:00'));
        $this->assertSame('18:30:00', $schedule->normalizedTime('18:30:00'));
        $this->assertSame('00:00:00', $schedule->normalizedTime(null));
    }
}
