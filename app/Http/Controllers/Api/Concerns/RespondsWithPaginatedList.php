<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithPaginatedList
{
    /**
     * Aplica búsqueda de texto si viene `search` en la query.
     *
     * @param  callable(\Illuminate\Database\Eloquent\Builder, string): void  $callback
     */
    protected function applySearch(Builder $query, Request $request, callable $callback): void
    {
        $term = trim((string) $request->query('search', ''));
        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(function (Builder $q) use ($callback, $like) {
            $callback($q, $like);
        });
    }

    /**
     * Si hay `page` (o paginate=1), responde { data, meta }.
     * Sin page, responde array plano (compatibilidad con selects/dropdowns).
     */
    protected function respondList(Request $request, Builder $query): JsonResponse
    {
        if ($request->filled('page') || $request->boolean('paginate')) {
            $perPage = (int) $request->query('per_page', 15);
            $perPage = max(1, min($perPage, 100));

            $paginator = $query->paginate($perPage);

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        }

        return response()->json($query->get());
    }
}
