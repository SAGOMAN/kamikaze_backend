<?php

namespace Tests\Feature;

use App\Models\Instructor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstructorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_store_with_valid_color_normalizes_to_uppercase(): void
    {
        $response = $this->postJson('/api/instructors', [
            'name' => 'Sensei Koji',
            'is_active' => true,
            'color' => '#3b82f6',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Sensei Koji')
            ->assertJsonPath('color', '#3B82F6');

        $this->assertDatabaseHas('instructors', [
            'name' => 'Sensei Koji',
            'color' => '#3B82F6',
        ]);
    }

    public function test_store_requires_color(): void
    {
        $this->postJson('/api/instructors', [
            'name' => 'Sensei Koji',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    }

    public function test_store_rejects_invalid_colors(): void
    {
        foreach (['red', '#FFF', '#GG0000', '#12345', '123456'] as $color) {
            $this->postJson('/api/instructors', [
                'name' => 'Sensei Koji',
                'color' => $color,
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['color']);
        }
    }

    public function test_update_can_change_color(): void
    {
        $instructor = Instructor::query()->create([
            'name' => 'Sensei Koji',
            'is_active' => true,
            'color' => '#64748B',
        ]);

        $this->putJson("/api/instructors/{$instructor->id}", [
            'color' => '#e11d48',
        ])->assertOk()
            ->assertJsonPath('color', '#E11D48');

        $this->assertDatabaseHas('instructors', [
            'id' => $instructor->id,
            'color' => '#E11D48',
        ]);
    }

    public function test_update_rejects_invalid_color(): void
    {
        $instructor = Instructor::query()->create([
            'name' => 'Sensei Koji',
            'is_active' => true,
            'color' => '#64748B',
        ]);

        $this->putJson("/api/instructors/{$instructor->id}", [
            'color' => '#FFF',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    }
}
