<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = Expense::query()
            ->with('branch')
            ->orderByDesc('expense_date');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->query('to'));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('category', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('branch', fn ($b) => $b->where('name', 'like', $like));
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $expense = Expense::query()->create($data);

        return response()->json($expense->load('branch'), 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json($expense->load('branch'));
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'expense_date' => ['sometimes', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $expense->update($data);

        return response()->json($expense->load('branch'));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(null, 204);
    }
}
