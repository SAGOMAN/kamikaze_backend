<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use Auditable, SoftDeletes;

    public const SOURCE_OPERATIONAL = 'operational';

    public const SOURCE_MERCHANDISE = 'merchandise';

    public const CATEGORY_MERCHANDISE = 'Mercancía';

    protected $fillable = [
        'category',
        'description',
        'amount',
        'expense_date',
        'branch_id',
        'notes',
        'source',
        'product_id',
        'quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'expense_date' => 'date',
            'quantity' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isMerchandise(): bool
    {
        return $this->source === self::SOURCE_MERCHANDISE;
    }
}
