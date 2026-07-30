<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Instructor::query()->orderBy('name');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $instructor = Instructor::query()->create($data);

        return response()->json($instructor, 201);
    }

    public function show(Instructor $instructor): JsonResponse
    {
        return response()->json($instructor->load('classSchedules.branch'));
    }

    public function update(Request $request, Instructor $instructor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $instructor->update($data);

        return response()->json($instructor);
    }

    public function destroy(Instructor $instructor): JsonResponse
    {
        $instructor->delete();

        return response()->json(null, 204);
    }
}
