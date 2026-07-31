<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductStockController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = ProductStock::query()->with(['product', 'branch']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->whereHas('product', function ($p) use ($like) {
                $p->where('name', 'like', $like)->orWhere('sku', 'like', $like);
            })->orWhereHas('branch', fn ($b) => $b->where('name', 'like', $like));
        });

        return $this->respondList($request, $query);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'quantity' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $stock = DB::transaction(function () use ($data) {
            $stock = ProductStock::query()->firstOrNew([
                'product_id' => $data['product_id'],
                'branch_id' => $data['branch_id'],
            ]);

            $previous = (int) ($stock->quantity ?? 0);
            $stock->quantity = $data['quantity'];
            $stock->save();

            $delta = $data['quantity'] - $previous;
            if ($delta !== 0) {
                StockMovement::query()->create([
                    'product_id' => $data['product_id'],
                    'branch_id' => $data['branch_id'],
                    'quantity' => $delta,
                    'type' => 'adjustment',
                    'notes' => $data['notes'] ?? null,
                ]);
            }

            return $stock;
        });

        return response()->json($stock->load(['product', 'branch']));
    }
}
