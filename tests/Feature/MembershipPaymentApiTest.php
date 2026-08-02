<?php

namespace Tests\Feature;

use App\Models\MembershipPayment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_filters_by_student_and_year(): void
    {
        $student = Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ]);
        $other = Student::query()->create([
            'first_name' => 'Luis',
            'last_name' => 'Gómez',
            'is_active' => true,
        ]);

        MembershipPayment::query()->create([
            'student_id' => $student->id,
            'amount' => 500,
            'payment_date' => '2026-03-10',
            'period_month' => '2026-03',
            'payment_method' => 'efectivo',
        ]);
        MembershipPayment::query()->create([
            'student_id' => $student->id,
            'amount' => 500,
            'payment_date' => '2025-12-01',
            'period_month' => '2025-12',
            'payment_method' => 'efectivo',
        ]);
        MembershipPayment::query()->create([
            'student_id' => $other->id,
            'amount' => 400,
            'payment_date' => '2026-03-15',
            'period_month' => '2026-03',
            'payment_method' => 'efectivo',
        ]);

        $response = $this->getJson(
            "/api/membership-payments?student_id={$student->id}&year=2026"
        );

        $response->assertOk();
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('2026-03', $data[0]['period_month']);
        $this->assertSame($student->id, $data[0]['student_id']);
    }
}
