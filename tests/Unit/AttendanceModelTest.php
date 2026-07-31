<?php

namespace Tests\Unit;

use App\Models\Attendance;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceModelTest extends TestCase
{
    #[Test]
    public function fillable_includes_class_schedule_id(): void
    {
        $model = new Attendance;

        $this->assertContains('class_schedule_id', $model->getFillable());
        $this->assertContains('student_id', $model->getFillable());
        $this->assertContains('branch_id', $model->getFillable());
        $this->assertContains('attendance_date', $model->getFillable());
        $this->assertContains('notes', $model->getFillable());
    }

    #[Test]
    public function defines_class_schedule_relation(): void
    {
        $model = new Attendance;

        $this->assertTrue(method_exists($model, 'classSchedule'));
        $this->assertTrue(method_exists($model, 'student'));
        $this->assertTrue(method_exists($model, 'branch'));
    }

    #[Test]
    public function uses_soft_deletes(): void
    {
        $traits = class_uses_recursive(Attendance::class);

        $this->assertArrayHasKey(SoftDeletes::class, $traits);
    }

    #[Test]
    public function casts_attendance_date_to_date(): void
    {
        $model = new Attendance;

        $this->assertSame('date', $model->getCasts()['attendance_date'] ?? null);
    }
}
