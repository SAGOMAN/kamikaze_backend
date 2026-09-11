<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'purchase_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $expense = DB::transaction(function () use ($data) {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $amount = round(((float) $data['unit_cost']) * (int) $data['quantity'], 2);

            $stock = ProductStock::query()->firstOrCreate(
                [
                    'product_id' => $product->id,
                    'branch_id' => $data['branch_id'],
                ],
                ['quantity' => 0]
            );
            $stock = ProductStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $stock->increment('quantity', (int) $data['quantity']);

            $expense = Expense::query()->create([
                'category' => Expense::CATEGORY_MERCHANDISE,
                'description' => $product->name.' × '.$data['quantity'],
                'amount' => $amount,
                'expense_date' => $data['purchase_date'],
                'branch_id' => $data['branch_id'],
                'notes' => $data['notes'] ?? null,
                'source' => Expense::SOURCE_MERCHANDISE,
                'product_id' => $product->id,
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
            ]);

            StockMovement::query()->create([
                'product_id' => $product->id,
                'branch_id' => $data['branch_id'],
                'quantity' => (int) $data['quantity'],
                'type' => 'restock',
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $product->update(['last_cost' => $data['unit_cost']]);

            return $expense->load(['branch', 'product']);
        });

        return response()->json($expense, 201);
    }
}
