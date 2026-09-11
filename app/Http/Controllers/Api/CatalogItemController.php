<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\CatalogItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogItemController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request, Catalog $catalog): JsonResponse
    {
        $query = CatalogItem::query()
            ->where('catalog_id', $catalog->id)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like);
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request, Catalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('catalog_items', 'name')->where(fn ($q) => $q->where('catalog_id', $catalog->id)->whereNull('deleted_at')),
            ],
            'code' => ['nullable', 'string', 'max:100', 'alpha_dash'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $item = $catalog->items()->create($data);

        return response()->json($item, 201);
    }

    public function update(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('catalog_items', 'name')
                    ->ignore($catalogItem->id)
                    ->where(fn ($q) => $q->where('catalog_id', $catalogItem->catalog_id)->whereNull('deleted_at')),
            ],
            'code' => ['nullable', 'string', 'max:100', 'alpha_dash'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $catalogItem->update($data);

        return response()->json($catalogItem);
    }

    public function destroy(CatalogItem $catalogItem): JsonResponse
    {
        $catalogItem->delete();

        return response()->json(null, 204);
    }
}
