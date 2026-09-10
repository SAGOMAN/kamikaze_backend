<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\ClassSchedule;
use App\Models\Instructor;
use App\Models\Student;
use App\Models\User;
use App\Support\BusinessClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private Student $student;

    private Instructor $instructor;

    private ClassSchedule $morning;

    private ClassSchedule $evening;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-30 10:30:00', BusinessClock::TIMEZONE));

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

        $this->instructor = Instructor::query()->create([
            'name' => 'Sensei Koji',
            'is_active' => true,
        ]);

        // 2026-07-30 = jueves = 4
        $this->morning = ClassSchedule::query()->create([
            'instructor_id' => $this->instructor->id,
            'branch_id' => $this->branch->id,
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        $this->evening = ClassSchedule::query()->create([
            'instructor_id' => $this->instructor->id,
            'branch_id' => $this->branch->id,
            'day_of_week' => 4,
            'start_time' => '18:00',
            'end_time' => '20:00',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_store_rejects_date_other_than_today(): void
    {
        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-31',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['attendance_date']);
    }

    public function test_store_rejects_schedule_for_wrong_weekday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 10:30:00', BusinessClock::TIMEZONE));

        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-31',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['class_schedule_id']);
    }

    public function test_store_rejects_schedule_not_occurring_now(): void
    {
        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->evening->id,
            'attendance_date' => '2026-07-30',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['class_schedule_id'])
            ->assertJsonFragment(['El horario no corresponde a la clase en curso.']);
    }

    public function test_store_rejects_inactive_schedule(): void
    {
        $inactive = ClassSchedule::query()->create([
            'instructor_id' => $this->instructor->id,
            'branch_id' => $this->branch->id,
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'is_active' => false,
        ]);

        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $inactive->id,
            'attendance_date' => '2026-07-30',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['class_schedule_id']);
    }

    public function test_student_cannot_attend_overlapping_schedule_other_branch(): void
    {
        $otherBranch = Branch::query()->create([
            'name' => 'Norte',
            'is_active' => true,
        ]);

        $otherMorning = ClassSchedule::query()->create([
            'instructor_id' => $this->instructor->id,
            'branch_id' => $otherBranch->id,
            'day_of_week' => 4,
            'start_time' => '10:00',
            'end_time' => '11:30',
            'is_active' => true,
        ]);

        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'branch_id' => $this->branch->id,
            'attendance_date' => '2026-07-30',
        ])->assertCreated();

        $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $otherMorning->id,
            'branch_id' => $otherBranch->id,
            'attendance_date' => '2026-07-30',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);

        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_student_can_attend_non_overlapping_later_schedule_same_day(): void
    {
        Attendance::query()->create([
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'branch_id' => $this->branch->id,
            'attendance_date' => '2026-07-30',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-30 18:30:00', BusinessClock::TIMEZONE));

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

        Attendance::query()->create([
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->evening->id,
            'branch_id' => $this->branch->id,
            'attendance_date' => '2026-07-30',
        ]);

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

    public function test_store_restores_soft_deleted_attendance(): void
    {
        $created = $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-30',
            'notes' => 'primera',
        ])->assertCreated()->json();

        $this->deleteJson('/api/attendances/'.$created['id'])->assertNoContent();
        $this->assertSoftDeleted('attendances', ['id' => $created['id']]);

        $restored = $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-30',
            'notes' => 'remarcada',
        ])->assertCreated()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->json();

        $this->assertSame($created['id'], $restored['id']);
        $this->assertSame('remarcada', $restored['notes']);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', [
            'id' => $created['id'],
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'notes' => 'remarcada',
            'deleted_at' => null,
        ]);
    }

    public function test_store_validation_errors_do_not_expose_sql(): void
    {
        $response = $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'attendance_date' => '2026-07-31',
        ])->assertStatus(422);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('attendances_unique_schedule_day', $body);
        $this->assertStringNotContainsString('"trace"', $body);
        $this->assertStringNotContainsString('"exception"', $body);
    }

    public function test_store_rejects_branch_mismatch_without_sql_leak(): void
    {
        $otherBranch = Branch::query()->create([
            'name' => 'Norte',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/attendances', [
            'student_id' => $this->student->id,
            'class_schedule_id' => $this->morning->id,
            'branch_id' => $otherBranch->id,
            'attendance_date' => '2026-07-30',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('"trace"', $body);
    }
}
