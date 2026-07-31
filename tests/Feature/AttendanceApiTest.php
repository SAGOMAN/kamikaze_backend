<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClassSchedule;
use App\Models\Instructor;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private Student $student;

    private ClassSchedule $morning;

    private ClassSchedule $evening;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->branch = Branch::query()->create([
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $this->student = Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ]);

        $instructor = Instructor::query()->create([
            'name' => 'Sensei Koji',
            'is_active' => true,
        ]);

        // 2026-07-30 = jueves = 4
        $this->morning = ClassSchedule::query()->create([
            'instructor_id' => $instructor->id,
            'branch_id' => $this->branch->id,
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $this->evening = ClassSchedule::query()->create([
            'instructor_id' => $instructor->id,
            'branch_id' => $this->branch->id,
            'day_of_week' => 4,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'is_active' => true,
        ]);
    }

    public function test_store_requires_class_schedule_id(): void
    {
        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'attendance_date' => '2026-07-30',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['class_schedule_id']);
    }

    public function test_store_rejects_schedule_for_wrong_weekday(): void
    {
        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-31', // viernes
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['class_schedule_id']);
    }

    public function test_student_can_attend_multiple_schedules_same_day(): void
    {
        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'branch_id' => $this->branch->id,
            'attendance_date' => '2026-07-30',
        ])->assertCreated();

        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->evening->id,
            'branch_id' => $this->branch->id,
            'attendance_date' => '2026-07-30',
        ])->assertCreated();

        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_index_filters_by_class_schedule_id(): void
    {
        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-30',
        ])->assertCreated();

        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->evening->id,
            'attendance_date' => '2026-07-30',
        ])->assertCreated();

        $response = $this->getJson('/api/attendances?date=2026-07-30&branch_id='.$this->branch->id.'&class_schedule_id='.$this->morning->id);

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame($this->morning->id, $response->json('0.class_schedule_id'));
    }

    public function test_store_is_idempotent_for_same_schedule_day(): void
    {
        $payload = [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-30',
            'notes' => 'primera',
        ];

        $this->postJson('/api/attendances', $payload)->assertCreated();
        $this->postJson('/api/attendances', array_merge($payload, ['notes' => 'segunda']))->assertCreated();

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'notes' => 'segunda',
        ]);
    }

    public function test_destroy_soft_deletes_attendance(): void
    {
        $created = $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-30',
        ])->assertCreated()->json();

        $this->deleteJson('/api/attendances/'.$created['id'])->assertNoContent();

        $this->assertSoftDeleted('attendances', ['id' => $created['id']]);
    }
}
