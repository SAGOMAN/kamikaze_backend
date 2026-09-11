<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = Catalog::query()
            ->with('items')
            ->orderBy('name');

        if ($request->filled('code')) {
            $query->where('code', $request->query('code'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('description', 'like', $like);
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('catalogs', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $catalog = Catalog::query()->create($data);

        return response()->json($catalog->load('items'), 201);
    }

    public function show(Catalog $catalog): JsonResponse
    {
        return response()->json($catalog->load('items'));
    }

    public function update(Request $request, Catalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('catalogs', 'code')->ignore($catalog->id)->whereNull('deleted_at'),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($catalog->isReserved() && isset($data['code']) && $data['code'] !== $catalog->code) {
            throw ValidationException::withMessages([
                'code' => ['No se puede cambiar el código de un catálogo reservado.'],
            ]);
        }

        $catalog->update($data);

        return response()->json($catalog->load('items'));
    }

    public function destroy(Catalog $catalog): JsonResponse
    {
        if ($catalog->isReserved()) {
            throw ValidationException::withMessages([
                'code' => ['No se puede eliminar el catálogo de categorías de gasto.'],
            ]);
        }

        $catalog->delete();

        return response()->json(null, 204);
    }
}
