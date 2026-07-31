<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_students_without_page_returns_plain_array(): void
    {
        Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/students');

        $response->assertOk();
        $this->assertIsArray($response->json());
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    public function test_students_with_page_returns_paginated_envelope(): void
    {
        Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ]);
        Student::query()->create([
            'first_name' => 'Luis',
            'last_name' => 'Gómez',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/students?page=1&per_page=1&search=Ana');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_branches_search_and_pagination(): void
    {
        Branch::query()->create(['name' => 'Centro', 'is_active' => true]);
        Branch::query()->create(['name' => 'Norte', 'is_active' => true]);

        $response = $this->getJson('/api/branches?page=1&per_page=15&search=Nor');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Norte');
    }
}
