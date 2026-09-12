<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class Catalog extends Model
{
    use Auditable, SoftDeletes;

    public const EXPENSE_CATEGORIES = 'expense_categories';
    public const PAYMENT_METHODS = 'payment_methods';

    /** @var list<string> */
    public const RESERVED_CODES = [
        self::EXPENSE_CATEGORIES,
        self::PAYMENT_METHODS,
    ];

    /** @var list<array{name: string, code: string, sort_order: int}> */
    public const DEFAULT_EXPENSE_CATEGORY_ITEMS = [
        ['name' => 'Bebidas', 'code' => 'bebidas', 'sort_order' => 1],
        ['name' => 'Implementos', 'code' => 'implementos', 'sort_order' => 2],
        ['name' => 'Comida', 'code' => 'comida', 'sort_order' => 3],
        ['name' => 'Servicios', 'code' => 'servicios', 'sort_order' => 4],
    ];

    /** @var list<array{name: string, code: string, sort_order: int}> */
    public const DEFAULT_PAYMENT_METHOD_ITEMS = [
        ['name' => 'Efectivo', 'code' => 'efectivo', 'sort_order' => 1],
        ['name' => 'Transferencia', 'code' => 'transferencia', 'sort_order' => 2],
        ['name' => 'De Una!', 'code' => 'de_una', 'sort_order' => 3],
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
        return in_array($this->code, self::RESERVED_CODES, true);
    }

    public static function itemNameRule(string $catalogCode, ?string $current = null): Exists
    {
        return Rule::exists('catalog_items', 'name')->where(function ($query) use ($catalogCode, $current) {
            $query->whereNull('deleted_at')
                ->whereIn(
                    'catalog_id',
                    static::query()->where('code', $catalogCode)->select('id')
                )
                ->where(function ($inner) use ($current) {
                    $inner->where('is_active', true);
                    if ($current) {
                        $inner->orWhere('name', $current);
                    }
                });
        });
    }

    public static function ensureExpenseCategories(?int $userId = null): self
    {
        return static::ensureCatalog(
            self::EXPENSE_CATEGORIES,
            'Categorías de gasto',
            'Valores del campo categoría en gastos operativos.',
            self::DEFAULT_EXPENSE_CATEGORY_ITEMS,
            $userId,
        );
    }

    public static function ensurePaymentMethods(?int $userId = null): self
    {
        return static::ensureCatalog(
            self::PAYMENT_METHODS,
            'Métodos de pago',
            'Valores del campo método en pagos de mensualidad.',
            self::DEFAULT_PAYMENT_METHOD_ITEMS,
            $userId,
        );
    }

    /**
     * @param  list<array{name: string, code: string, sort_order: int}>  $items
     */
    private static function ensureCatalog(
        string $code,
        string $name,
        string $description,
        array $items,
        ?int $userId = null,
    ): self {
        $catalog = static::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => $description,
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        foreach ($items as $item) {
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
