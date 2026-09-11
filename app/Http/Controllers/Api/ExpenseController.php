<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Expense;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'source' => ['sometimes', 'nullable', Rule::in([Expense::SOURCE_OPERATIONAL, Expense::SOURCE_MERCHANDISE])],
        ]);

        $query = Expense::query()
            ->with(['branch', 'product'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($request->filled('source')) {
            $query->where('source', $request->query('source'));
        }

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
                ->orWhereHas('branch', fn ($b) => $b->where('name', 'like', $like))
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like));
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100', $this->expenseCategoryRule()],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['source'] = Expense::SOURCE_OPERATIONAL;

        $expense = Expense::query()->create($data);

        return response()->json($expense->load(['branch', 'product']), 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        return response()->json($expense->load(['branch', 'product']));
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        if ($expense->isMerchandise()) {
            $locked = ['category', 'description', 'amount', 'branch_id', 'product_id', 'quantity', 'unit_cost', 'source'];
            foreach ($locked as $field) {
                if ($request->exists($field)) {
                    throw ValidationException::withMessages([
                        $field => ['Las compras de mercancía solo permiten cambiar fecha y notas.'],
                    ]);
                }
            }

            $data = $request->validate([
                'expense_date' => ['sometimes', 'date'],
                'notes' => ['nullable', 'string'],
            ]);
        } else {
            $data = $request->validate([
                'category' => ['sometimes', 'string', 'max:100', $this->expenseCategoryRule($expense->category)],
                'description' => ['nullable', 'string', 'max:255'],
                'amount' => ['sometimes', 'numeric', 'min:0'],
                'expense_date' => ['sometimes', 'date'],
                'branch_id' => ['nullable', 'exists:branches,id'],
                'notes' => ['nullable', 'string'],
            ]);
        }

        $expense->update($data);

        return response()->json($expense->load(['branch', 'product']));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        if (! $expense->isMerchandise()) {
            $expense->delete();

            return response()->json(null, 204);
        }

        DB::transaction(function () use ($expense) {
            $quantity = (int) $expense->quantity;
            $stock = ProductStock::query()
                ->where('product_id', $expense->product_id)
                ->where('branch_id', $expense->branch_id)
                ->lockForUpdate()
                ->first();

            $current = (int) ($stock?->quantity ?? 0);
            if ($current < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['No se puede anular: ya se vendió parte del stock.'],
                ]);
            }

            if ($stock && $quantity > 0) {
                $stock->decrement('quantity', $quantity);
            }

            StockMovement::query()->create([
                'product_id' => $expense->product_id,
                'branch_id' => $expense->branch_id,
                'quantity' => -$quantity,
                'type' => 'adjustment',
                'notes' => 'Reversión por anulación de compra #'.$expense->id,
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
            ]);

            $expense->delete();
        });

        return response()->json(null, 204);
    }

    private function expenseCategoryRule(?string $current = null): Exists
    {
        return Rule::exists('catalog_items', 'name')->where(function ($query) use ($current) {
            $query->whereNull('deleted_at')
                ->whereIn(
                    'catalog_id',
                    Catalog::query()->where('code', Catalog::EXPENSE_CATEGORIES)->select('id')
                )
                ->where(function ($inner) use ($current) {
                    $inner->where('is_active', true);
                    if ($current) {
                        $inner->orWhere('name', $current);
                    }
                });
        });
    }
}
