<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Sale::query()
            ->with(['branch', 'items.product'])
            ->orderByDesc('sale_date');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('sale_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sale_date', '<=', $request->query('to'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'sale_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sale = DB::transaction(function () use ($data) {
            $sale = Sale::query()->create([
                'branch_id' => $data['branch_id'],
                'sale_date' => $data['sale_date'],
                'notes' => $data['notes'] ?? null,
                'total' => 0,
            ]);

            $total = 0;

            foreach ($data['items'] as $item) {
                $product = Product::query()->findOrFail($item['product_id']);
                $unitPrice = $item['unit_price'] ?? $product->unit_price;
                $subtotal = round($unitPrice * $item['quantity'], 2);
                $total += $subtotal;

                $stock = ProductStock::query()->firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'branch_id' => $data['branch_id'],
                    ],
                    ['quantity' => 0]
                );

                if ($stock->quantity < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Stock insuficiente de {$product->name} en la sucursal."],
                    ]);
                }

                $stock->decrement('quantity', $item['quantity']);

                $sale->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ]);

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'branch_id' => $data['branch_id'],
                    'quantity' => -$item['quantity'],
                    'type' => 'sale',
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                ]);
            }

            $sale->update(['total' => $total]);

            return $sale->load(['branch', 'items.product']);
        });

        return response()->json($sale, 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json($sale->load(['branch', 'items.product']));
    }

    public function destroy(Sale $sale): JsonResponse
    {
        DB::transaction(function () use ($sale) {
            $sale->load('items');

            foreach ($sale->items as $item) {
                $stock = ProductStock::query()->firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'branch_id' => $sale->branch_id,
                    ],
                    ['quantity' => 0]
                );
                $stock->increment('quantity', $item->quantity);

                StockMovement::query()->create([
                    'product_id' => $item->product_id,
                    'branch_id' => $sale->branch_id,
                    'quantity' => $item->quantity,
                    'type' => 'adjustment',
                    'notes' => 'Reversión por eliminación de venta #'.$sale->id,
                    'reference_type' => Sale::class,
                    'reference_id' => $sale->id,
                ]);
            }

            $sale->delete();
        });

        return response()->json(null, 204);
    }
}
