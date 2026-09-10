<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@test.style',
            'role' => User::ROLE_ADMIN,
        ]);

        Sanctum::actingAs($this->admin);
    }

    public function test_admin_can_list_and_create_users(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Staff Uno',
            'email' => 'staff1@test.style',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_STAFF,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('email', 'staff1@test.style')
            ->assertJsonPath('role', User::ROLE_STAFF)
            ->assertJsonMissingPath('password');

        $this->getJson('/api/users?page=1&search=Staff')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Staff Uno');
    }

    public function test_staff_cannot_manage_users(): void
    {
        $staff = User::factory()->staff()->create();
        Sanctum::actingAs($staff);

        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', [
            'name' => 'Otro',
            'email' => 'otro@test.style',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_STAFF,
        ])->assertForbidden();
    }

    public function test_cannot_deactivate_self(): void
    {
        $this->putJson("/api/users/{$this->admin->id}", [
            'is_active' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_cannot_demote_last_admin(): void
    {
        $this->putJson("/api/users/{$this->admin->id}", [
            'role' => User::ROLE_STAFF,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_admin_can_reset_password(): void
    {
        $target = User::factory()->staff()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->putJson("/api/users/{$target->id}/password", [
            'password' => 'new-password1',
            'password_confirmation' => 'new-password1',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password1', $target->fresh()->password));
    }

    public function test_user_can_change_own_password(): void
    {
        $this->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'brand-new-1',
            'password_confirmation' => 'brand-new-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-1', $this->admin->fresh()->password));
    }

    public function test_change_own_password_requires_current(): void
    {
        $this->putJson('/api/me/password', [
            'current_password' => 'wrong',
            'password' => 'brand-new-1',
            'password_confirmation' => 'brand-new-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'email' => 'inactive@test.style',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@test.style',
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_active_user_can_login_with_role(): void
    {
        $this->postJson('/api/login', [
            'email' => 'admin@test.style',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.role', User::ROLE_ADMIN)
            ->assertJsonPath('user.is_active', true);
    }
}
