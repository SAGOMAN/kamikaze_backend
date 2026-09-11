<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Catalog;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());

        $this->branch = Branch::query()->create([
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $this->product = Product::query()->create([
            'name' => 'Guantes',
            'sku' => 'IMP-003',
            'unit_price' => 450,
            'is_active' => true,
        ]);

        Catalog::ensureExpenseCategories();
    }

    public function test_purchase_creates_merchandise_expense_and_increments_stock(): void
    {
        $response = $this->postJson('/api/purchases', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 10,
            'unit_cost' => 50,
            'purchase_date' => '2026-09-10',
            'notes' => 'Pedido inicial',
        ]);

        $response->assertCreated()
            ->assertJsonPath('source', Expense::SOURCE_MERCHANDISE)
            ->assertJsonPath('category', Expense::CATEGORY_MERCHANDISE)
            ->assertJsonPath('amount', '500.00')
            ->assertJsonPath('quantity', 10)
            ->assertJsonPath('unit_cost', '50.00')
            ->assertJsonPath('product_id', $this->product->id)
            ->assertJsonPath('branch_id', $this->branch->id);

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 10,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 10,
            'type' => 'restock',
        ]);

        $this->assertSame('50.00', $this->product->fresh()->last_cost);
    }

    public function test_expenses_can_be_filtered_by_source(): void
    {
        Expense::query()->create([
            'category' => 'Renta',
            'amount' => 100,
            'expense_date' => '2026-09-01',
            'source' => Expense::SOURCE_OPERATIONAL,
        ]);

        $this->postJson('/api/purchases', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 2,
            'unit_cost' => 10,
            'purchase_date' => '2026-09-02',
        ])->assertCreated();

        $operational = $this->getJson('/api/expenses?source=operational');
        $operational->assertOk();
        $this->assertCount(1, $operational->json());
        $this->assertSame('Renta', $operational->json('0.category'));

        $merchandise = $this->getJson('/api/expenses?source=merchandise');
        $merchandise->assertOk();
        $this->assertCount(1, $merchandise->json());
        $this->assertSame(Expense::SOURCE_MERCHANDISE, $merchandise->json('0.source'));
    }

    public function test_deleting_purchase_reverses_stock(): void
    {
        $expenseId = $this->postJson('/api/purchases', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 8,
            'unit_cost' => 20,
            'purchase_date' => '2026-09-10',
        ])->json('id');

        $this->deleteJson("/api/expenses/{$expenseId}")->assertNoContent();

        $this->assertSoftDeleted('expenses', ['id' => $expenseId]);
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 0,
        ]);
        $this->assertSame(
            1,
            StockMovement::query()->where('type', 'adjustment')->where('quantity', -8)->count()
        );
    }

    public function test_cannot_delete_purchase_if_stock_already_sold(): void
    {
        $expenseId = $this->postJson('/api/purchases', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 5,
            'unit_cost' => 30,
            'purchase_date' => '2026-09-10',
        ])->json('id');

        $this->postJson('/api/sales', [
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-09-10',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $this->deleteJson("/api/expenses/{$expenseId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'deleted_at' => null]);
        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 3,
        ]);
    }

    public function test_merchandise_expense_only_allows_date_and_notes_update(): void
    {
        $expenseId = $this->postJson('/api/purchases', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 3,
            'unit_cost' => 15,
            'purchase_date' => '2026-09-10',
        ])->json('id');

        $this->putJson("/api/expenses/{$expenseId}", [
            'amount' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->putJson("/api/expenses/{$expenseId}", [
            'expense_date' => '2026-09-11',
            'notes' => 'Factura 12',
        ])->assertOk()
            ->assertJsonPath('notes', 'Factura 12');

        $this->assertSame('2026-09-11', Expense::query()->find($expenseId)?->expense_date?->toDateString());
        $this->assertSame('45.00', Expense::query()->find($expenseId)?->amount);
    }

    public function test_operational_expense_store_ignores_merchandise_source(): void
    {
        $response = $this->postJson('/api/expenses', [
            'category' => 'Servicios',
            'amount' => 1200,
            'expense_date' => '2026-09-01',
            'source' => Expense::SOURCE_MERCHANDISE,
        ]);

        $response->assertCreated()
            ->assertJsonPath('source', Expense::SOURCE_OPERATIONAL)
            ->assertJsonPath('product_id', null);
    }

    public function test_purchase_adds_to_existing_stock(): void
    {
        ProductStock::query()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 4,
        ]);

        $this->postJson('/api/purchases', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 6,
            'unit_cost' => 12.5,
            'purchase_date' => '2026-09-10',
        ])->assertCreated()->assertJsonPath('amount', '75.00');

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'quantity' => 10,
        ]);
    }
}
