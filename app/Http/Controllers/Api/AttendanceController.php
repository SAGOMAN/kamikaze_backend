<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassSchedule;
use App\Support\BusinessClock;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()
            ->with(['student', 'branch', 'classSchedule.instructor', 'classSchedule.branch'])
            ->orderByDesc('attendance_date');

        if ($request->filled('date')) {
            $query->whereDate('attendance_date', $request->query('date'));
        } else {
            if ($request->filled('from')) {
                $query->whereDate('attendance_date', '>=', $request->query('from'));
            }
            if ($request->filled('to')) {
                $query->whereDate('attendance_date', '<=', $request->query('to'));
            }
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->query('student_id'));
        }

        if ($request->filled('class_schedule_id')) {
            $query->where('class_schedule_id', $request->query('class_schedule_id'));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('notes', 'like', $like)
                ->orWhereHas('student', function ($s) use ($like) {
                    $s->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('nickname', 'like', $like);
                });
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'class_schedule_id' => ['required', 'exists:class_schedules,id'],
            'attendance_date' => ['required', 'date'],
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $schedule = ClassSchedule::query()->findOrFail($data['class_schedule_id']);
        $attendanceDate = Carbon::parse($data['attendance_date'])->toDateString();

        $this->assertCanMark($data, $schedule, $attendanceDate);

        try {
            $attendance = DB::transaction(function () use ($data, $schedule, $attendanceDate) {
                $this->assertNoOverlappingPresence(
                    (int) $data['student_id'],
                    $schedule,
                    $attendanceDate,
                );

                return $this->upsertAttendance($data, $schedule);
            });
        } catch (UniqueConstraintViolationException) {
            $attendance = DB::transaction(function () use ($data, $schedule, $attendanceDate) {
                $this->assertNoOverlappingPresence(
                    (int) $data['student_id'],
                    $schedule,
                    $attendanceDate,
                );

                $existing = $this->findAttendanceIncludingTrashed(
                    (int) $data['student_id'],
                    (int) $data['class_schedule_id'],
                    $data['attendance_date'],
                );

                if (! $existing) {
                    throw ValidationException::withMessages([
                        'student_id' => ['Ya existe una asistencia para este alumno, horario y fecha.'],
                    ]);
                }

                return $this->restoreAndUpdateAttendance($existing, $schedule, $data['notes'] ?? null);
            });
        }

        return response()->json(
            $attendance->load(['student', 'branch', 'classSchedule.instructor']),
            201
        );
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_schedule_id' => ['required', 'exists:class_schedules,id'],
            'attendance_date' => ['required', 'date'],
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'student_ids' => ['present', 'array'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ]);

        $schedule = ClassSchedule::query()->findOrFail($data['class_schedule_id']);
        $attendanceDate = Carbon::parse($data['attendance_date'])->toDateString();
        $studentIds = array_values(array_unique(array_map('intval', $data['student_ids'])));
        sort($studentIds);

        $this->assertCanMark($data, $schedule, $attendanceDate);

        try {
            $attendances = DB::transaction(function () use ($data, $schedule, $attendanceDate, $studentIds) {
                foreach ($studentIds as $index => $studentId) {
                    $this->assertNoOverlappingPresence(
                        $studentId,
                        $schedule,
                        $attendanceDate,
                        "student_ids.{$index}",
                    );

                    $this->upsertAttendance([
                        'student_id' => $studentId,
                        'class_schedule_id' => $schedule->id,
                        'attendance_date' => $attendanceDate,
                        'notes' => $data['notes'] ?? null,
                    ], $schedule);
                }

                $toRemove = Attendance::query()
                    ->where('class_schedule_id', $schedule->id)
                    ->whereDate('attendance_date', $attendanceDate)
                    ->when($studentIds !== [], fn ($query) => $query->whereNotIn('student_id', $studentIds))
                    ->get();

                $toRemove->each->delete();

                return Attendance::query()
                    ->with(['student', 'branch', 'classSchedule.instructor'])
                    ->where('class_schedule_id', $schedule->id)
                    ->whereDate('attendance_date', $attendanceDate)
                    ->orderBy('id')
                    ->get();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'student_ids' => ['No se pudo registrar la lista de asistencia. Inténtalo de nuevo.'],
            ]);
        }

        return response()->json($attendances);
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  array{student_id: int|string, class_schedule_id: int|string, attendance_date: string, branch_id?: int|string, notes?: string|null}  $data
     */
    private function assertCanMark(array $data, ClassSchedule $schedule, string $attendanceDate): void
    {
        $today = BusinessClock::todayDate();

        if ($attendanceDate > $today) {
            throw ValidationException::withMessages([
                'attendance_date' => ['No se puede marcar asistencia de un día futuro.'],
            ]);
        }

        if (! $schedule->is_active) {
            throw ValidationException::withMessages([
                'class_schedule_id' => ['El horario no está activo.'],
            ]);
        }

        if ((int) $schedule->day_of_week !== (int) Carbon::parse($attendanceDate)->dayOfWeek) {
            throw ValidationException::withMessages([
                'class_schedule_id' => ['El horario no corresponde al día de la fecha indicada.'],
            ]);
        }

        if ($attendanceDate === $today && ! $schedule->occursAt(BusinessClock::now())) {
            throw ValidationException::withMessages([
                'class_schedule_id' => ['El horario no corresponde a la clase en curso.'],
            ]);
        }

        if (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $schedule->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => ['La sucursal no coincide con la del horario seleccionado.'],
            ]);
        }
    }

    private function assertNoOverlappingPresence(
        int $studentId,
        ClassSchedule $schedule,
        string $attendanceDate,
        string $errorKey = 'student_id',
    ): void {
        $others = Attendance::query()
            ->where('student_id', $studentId)
            ->whereDate('attendance_date', $attendanceDate)
            ->where('class_schedule_id', '!=', $schedule->id)
            ->whereNotNull('class_schedule_id')
            ->with(['classSchedule.branch'])
            ->lockForUpdate()
            ->get();

        foreach ($others as $other) {
            $otherSchedule = $other->classSchedule;
            if (! $otherSchedule || ! $schedule->overlapsWith($otherSchedule)) {
                continue;
            }

            $branchName = $otherSchedule->branch?->name;
            $message = $branchName
                ? "El alumno ya está presente en otro horario (sucursal {$branchName})."
                : 'El alumno ya está presente en otro horario o sucursal.';

            throw ValidationException::withMessages([
                $errorKey => [$message],
            ]);
        }
    }

    /**
     * @param  array{student_id: int|string, class_schedule_id: int|string, attendance_date: string, notes?: string|null}  $data
     */
    private function upsertAttendance(array $data, ClassSchedule $schedule): Attendance
    {
        $attendance = $this->findAttendanceIncludingTrashed(
            (int) $data['student_id'],
            (int) $data['class_schedule_id'],
            $data['attendance_date'],
        );

        if ($attendance) {
            return $this->restoreAndUpdateAttendance($attendance, $schedule, $data['notes'] ?? null);
        }

        return Attendance::query()->create([
            'student_id' => $data['student_id'],
            'class_schedule_id' => $data['class_schedule_id'],
            'attendance_date' => $data['attendance_date'],
            'branch_id' => $schedule->branch_id,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function findAttendanceIncludingTrashed(int $studentId, int $classScheduleId, string $attendanceDate): ?Attendance
    {
        return Attendance::withTrashed()
            ->where('student_id', $studentId)
            ->where('class_schedule_id', $classScheduleId)
            ->whereDate('attendance_date', $attendanceDate)
            ->first();
    }

    private function restoreAndUpdateAttendance(Attendance $attendance, ClassSchedule $schedule, ?string $notes): Attendance
    {
        if ($attendance->trashed()) {
            $attendance->restore();
        }

        $attendance->update([
            'branch_id' => $schedule->branch_id,
            'notes' => $notes,
        ]);

        return $attendance->refresh();
    }
}
