<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Catalog extends Model
{
    use Auditable, SoftDeletes;

    public const EXPENSE_CATEGORIES = 'expense_categories';

    /** @var list<array{name: string, code: string, sort_order: int}> */
    public const DEFAULT_EXPENSE_CATEGORY_ITEMS = [
        ['name' => 'Bebidas', 'code' => 'bebidas', 'sort_order' => 1],
        ['name' => 'Implementos', 'code' => 'implementos', 'sort_order' => 2],
        ['name' => 'Comida', 'code' => 'comida', 'sort_order' => 3],
        ['name' => 'Servicios', 'code' => 'servicios', 'sort_order' => 4],
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class)->orderBy('sort_order')->orderBy('name');
    }

    public function isReserved(): bool
    {
        return $this->code === self::EXPENSE_CATEGORIES;
    }

    public static function ensureExpenseCategories(?int $userId = null): self
    {
        $catalog = static::query()->firstOrCreate(
            ['code' => self::EXPENSE_CATEGORIES],
            [
                'name' => 'Categorías de gasto',
                'description' => 'Valores del campo categoría en gastos operativos.',
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        foreach (self::DEFAULT_EXPENSE_CATEGORY_ITEMS as $item) {
            $catalog->items()->firstOrCreate(
                ['code' => $item['code']],
                $item + [
                    'is_active' => true,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );
        }

        return $catalog->load('items');
    }
}
