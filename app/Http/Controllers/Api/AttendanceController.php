<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()
            ->with(['student', 'branch', 'classSchedule.instructor'])
            ->orderByDesc('attendance_date');

        if ($request->filled('date')) {
            $query->whereDate('attendance_date', $request->query('date'));
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

        $attendanceDate = Carbon::parse($data['attendance_date']);
        if ((int) $schedule->day_of_week !== (int) $attendanceDate->dayOfWeek) {
            throw ValidationException::withMessages([
                'class_schedule_id' => ['El horario no corresponde al día de la fecha indicada.'],
            ]);
        }

        if (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $schedule->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => ['La sucursal no coincide con la del horario seleccionado.'],
            ]);
        }

        $attendance = Attendance::query()
            ->where('student_id', $data['student_id'])
            ->where('class_schedule_id', $data['class_schedule_id'])
            ->whereDate('attendance_date', $data['attendance_date'])
            ->first();

        if ($attendance) {
            $attendance->update([
                'branch_id' => $schedule->branch_id,
                'notes' => $data['notes'] ?? null,
            ]);
        } else {
            $attendance = Attendance::query()->create([
                'student_id' => $data['student_id'],
                'class_schedule_id' => $data['class_schedule_id'],
                'attendance_date' => $data['attendance_date'],
                'branch_id' => $schedule->branch_id,
                'notes' => $data['notes'] ?? null,
            ]);
        }

        return response()->json(
            $attendance->load(['student', 'branch', 'classSchedule.instructor']),
            201
        );
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return response()->json(null, 204);
    }
}
