<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()
            ->with(['student', 'branch'])
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

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'attendance_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $attendance = Attendance::query()->updateOrCreate(
            [
                'student_id' => $data['student_id'],
                'branch_id' => $data['branch_id'],
                'attendance_date' => $data['attendance_date'],
            ],
            [
                'notes' => $data['notes'] ?? null,
            ]
        );

        return response()->json($attendance->load(['student', 'branch']), 201);
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return response()->json(null, 204);
    }
}
