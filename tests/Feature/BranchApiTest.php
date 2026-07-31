<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_store_with_valid_color_normalizes_to_uppercase(): void
    {
        $response = $this->postJson('/api/branches', [
            'name' => 'Sur',
            'address' => 'Av. Sur 1',
            'is_active' => true,
            'color' => '#c45c26',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Sur')
            ->assertJsonPath('color', '#C45C26');

        $this->assertDatabaseHas('branches', [
            'name' => 'Sur',
            'color' => '#C45C26',
        ]);
    }

    public function test_store_requires_color(): void
    {
        $this->postJson('/api/branches', [
            'name' => 'Sur',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    }

    public function test_store_rejects_invalid_colors(): void
    {
        foreach (['red', '#FFF', '#GG0000', '#12345', '123456'] as $color) {
            $this->postJson('/api/branches', [
                'name' => 'Sur',
                'color' => $color,
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['color']);
        }
    }

    public function test_update_can_change_color(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Centro',
            'is_active' => true,
            'color' => '#64748B',
        ]);

        $this->putJson("/api/branches/{$branch->id}", [
            'color' => '#1f6b4a',
        ])->assertOk()
            ->assertJsonPath('color', '#1F6B4A');

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'color' => '#1F6B4A',
        ]);
    }

    public function test_update_rejects_invalid_color(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Centro',
            'is_active' => true,
            'color' => '#64748B',
        ]);

        $this->putJson("/api/branches/{$branch->id}", [
            'color' => 'red',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    }
}
