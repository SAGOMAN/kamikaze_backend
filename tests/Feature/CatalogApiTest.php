<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_seeder_creates_expense_categories(): void
    {
        $catalog = Catalog::ensureExpenseCategories();

        $this->assertSame(Catalog::EXPENSE_CATEGORIES, $catalog->code);
        $this->assertCount(4, $catalog->items);
        $this->assertEqualsCanonicalizing(
            ['Bebidas', 'Implementos', 'Comida', 'Servicios'],
            $catalog->items->pluck('name')->all()
        );
    }

    public function test_index_can_filter_by_code_and_includes_items(): void
    {
        Catalog::ensureExpenseCategories();

        $response = $this->getJson('/api/catalogs?code=expense_categories');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Categorías de gasto', $response->json('0.name'));
        $this->assertCount(4, $response->json('0.items'));
    }

    public function test_can_create_catalog_and_items(): void
    {
        $catalogId = $this->postJson('/api/catalogs', [
            'code' => 'demo_list',
            'name' => 'Lista de prueba',
        ])->assertCreated()->json('id');

        $this->postJson("/api/catalogs/{$catalogId}/items", [
            'name' => 'Efectivo',
            'code' => 'efectivo',
            'sort_order' => 1,
        ])->assertCreated()->assertJsonPath('name', 'Efectivo');

        $this->getJson("/api/catalogs/{$catalogId}/items")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_cannot_delete_reserved_expense_catalog(): void
    {
        $catalog = Catalog::ensureExpenseCategories();

        $this->deleteJson("/api/catalogs/{$catalog->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseHas('catalogs', [
            'id' => $catalog->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_rename_reserved_catalog_code(): void
    {
        $catalog = Catalog::ensureExpenseCategories();

        $this->putJson("/api/catalogs/{$catalog->id}", [
            'code' => 'otra-cosa',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_can_update_and_delete_item(): void
    {
        $catalog = Catalog::ensureExpenseCategories();
        $item = $catalog->items()->where('code', 'bebidas')->firstOrFail();

        $this->putJson("/api/catalog-items/{$item->id}", [
            'name' => 'Bebidas frías',
        ])->assertOk()->assertJsonPath('name', 'Bebidas frías');

        $this->deleteJson("/api/catalog-items/{$item->id}")->assertNoContent();
        $this->assertSoftDeleted('catalog_items', ['id' => $item->id]);
    }

    public function test_expense_store_requires_catalog_category(): void
    {
        Catalog::ensureExpenseCategories();

        $this->postJson('/api/expenses', [
            'category' => 'Renta',
            'amount' => 100,
            'expense_date' => '2026-09-10',
        ])->assertStatus(422)->assertJsonValidationErrors(['category']);

        $this->postJson('/api/expenses', [
            'category' => 'Servicios',
            'amount' => 100,
            'expense_date' => '2026-09-10',
        ])->assertCreated()->assertJsonPath('category', 'Servicios');
    }

    public function test_expense_update_keeps_historical_inactive_category(): void
    {
        $catalog = Catalog::ensureExpenseCategories();
        $item = $catalog->items()->where('code', 'comida')->firstOrFail();

        $expenseId = $this->postJson('/api/expenses', [
            'category' => 'Comida',
            'amount' => 40,
            'expense_date' => '2026-09-10',
        ])->assertCreated()->json('id');

        $item->update(['is_active' => false]);

        $this->putJson("/api/expenses/{$expenseId}", [
            'category' => 'Comida',
            'notes' => 'Ticket 1',
        ])->assertOk()->assertJsonPath('notes', 'Ticket 1');
    }

    public function test_seeder_creates_payment_methods(): void
    {
        $catalog = Catalog::ensurePaymentMethods();

        $this->assertSame(Catalog::PAYMENT_METHODS, $catalog->code);
        $this->assertEqualsCanonicalizing(
            ['Efectivo', 'Transferencia', 'De Una!'],
            $catalog->items->pluck('name')->all()
        );
    }

    public function test_cannot_delete_reserved_payment_methods_catalog(): void
    {
        $catalog = Catalog::ensurePaymentMethods();

        $this->deleteJson("/api/catalogs/{$catalog->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_membership_payment_store_requires_catalog_method(): void
    {
        Catalog::ensurePaymentMethods();
        $studentId = Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ])->id;

        $this->postJson('/api/membership-payments', [
            'student_id' => $studentId,
            'amount' => 500,
            'payment_date' => '2026-09-12',
            'period_month' => '2026-09',
            'payment_method' => 'Cheque',
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);

        $this->postJson('/api/membership-payments', [
            'student_id' => $studentId,
            'amount' => 500,
            'payment_date' => '2026-09-12',
            'period_month' => '2026-09',
            'payment_method' => 'De Una!',
        ])->assertCreated()->assertJsonPath('payment_method', 'De Una!');
    }

    public function test_membership_payment_update_keeps_historical_inactive_method(): void
    {
        $catalog = Catalog::ensurePaymentMethods();
        $item = $catalog->items()->where('code', 'efectivo')->firstOrFail();
        $studentId = Student::query()->create([
            'first_name' => 'Luis',
            'last_name' => 'Gómez',
            'is_active' => true,
        ])->id;

        $paymentId = $this->postJson('/api/membership-payments', [
            'student_id' => $studentId,
            'amount' => 400,
            'payment_date' => '2026-09-12',
            'period_month' => '2026-09',
            'payment_method' => 'Efectivo',
        ])->assertCreated()->json('id');

        $item->update(['is_active' => false]);

        $this->putJson("/api/membership-payments/{$paymentId}", [
            'payment_method' => 'Efectivo',
            'notes' => 'Histórico',
        ])->assertOk()->assertJsonPath('notes', 'Histórico');
    }
}
