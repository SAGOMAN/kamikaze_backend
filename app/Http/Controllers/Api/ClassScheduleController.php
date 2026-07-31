<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassScheduleController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = ClassSchedule::query()
            ->with(['instructor', 'branch'])
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->query('instructor_id'));
        }

        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->query('day_of_week'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('notes', 'like', $like)
                ->orWhereHas('instructor', fn ($i) => $i->where('name', 'like', $like))
                ->orWhereHas('branch', fn ($b) => $b->where('name', 'like', $like));
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instructor_id' => ['required', 'exists:instructors,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $schedule = ClassSchedule::query()->create($data);

        return response()->json($schedule->load(['instructor', 'branch']), 201);
    }

    public function show(ClassSchedule $classSchedule): JsonResponse
    {
        return response()->json($classSchedule->load(['instructor', 'branch']));
    }

    public function update(Request $request, ClassSchedule $classSchedule): JsonResponse
    {
        $data = $request->validate([
            'instructor_id' => ['sometimes', 'exists:instructors,id'],
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'day_of_week' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $classSchedule->update($data);

        return response()->json($classSchedule->load(['instructor', 'branch']));
    }

    public function destroy(ClassSchedule $classSchedule): JsonResponse
    {
        $classSchedule->delete();

        return response()->json(null, 204);
    }
}
