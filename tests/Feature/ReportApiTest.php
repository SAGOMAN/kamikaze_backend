<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\ClassSchedule;
use App\Models\Expense;
use App\Models\Instructor;
use App\Models\MembershipPayment;
use App\Models\Sale;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());

        $this->branch = Branch::query()->create([
            'name' => 'Centro',
            'is_active' => true,
        ]);
    }

    public function test_monthly_report_includes_tops(): void
    {
        $students = collect(range(1, 6))->map(fn (int $i) => Student::query()->create([
            'first_name' => "Alumno{$i}",
            'last_name' => 'Test',
            'is_active' => true,
        ]));

        foreach (range(1, 6) as $i) {
            Sale::query()->create([
                'branch_id' => $this->branch->id,
                'sale_date' => '2026-08-0'.min($i, 9),
                'total' => $i * 100,
                'notes' => "Venta {$i}",
            ]);

            Expense::query()->create([
                'category' => "Cat {$i}",
                'description' => "Gasto {$i}",
                'amount' => $i * 50,
                'expense_date' => '2026-08-0'.min($i, 9),
                'branch_id' => $this->branch->id,
            ]);

            MembershipPayment::query()->create([
                'student_id' => $students[$i - 1]->id,
                'amount' => $i * 200,
                'payment_date' => '2026-08-0'.min($i, 9),
                'period_month' => '2026-08',
                'payment_method' => 'efectivo',
            ]);
        }

        // Outside month — must not appear in tops
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-07-15',
            'total' => 9999,
        ]);

        $instructor = Instructor::query()->create([
            'name' => 'Sensei',
            'is_active' => true,
        ]);
        $schedule = ClassSchedule::query()->create([
            'instructor_id' => $instructor->id,
            'branch_id' => $this->branch->id,
            'day_of_week' => 6, // sábado 2026-08-01
            'start_time' => '10:00',
            'end_time' => '11:00',
            'is_active' => true,
        ]);

        // Attendances: alumno6=6, alumno5=5, … alumno1=1 → top5 excludes alumno1
        foreach (range(1, 6) as $i) {
            $student = $students[$i - 1];
            for ($n = 0; $n < $i; $n++) {
                Attendance::query()->create([
                    'student_id' => $student->id,
                    'branch_id' => $this->branch->id,
                    'class_schedule_id' => $schedule->id,
                    'attendance_date' => sprintf('2026-08-%02d', $n + 1),
                ]);
            }
        }

        $response = $this->getJson('/api/reports/monthly?year=2026&month=8');

        $response->assertOk()
            ->assertJsonPath('income.sales', 2100)
            ->assertJsonPath('income.membership_payments', 4200)
            ->assertJsonPath('expenses.total', 1050)
            ->assertJsonPath('balance', 5250);

        $tops = $response->json('tops');
        $this->assertCount(5, $tops['sales']);
        $this->assertSame(600.0, (float) $tops['sales'][0]['total']);
        $this->assertSame(200.0, (float) $tops['sales'][4]['total']);

        $this->assertCount(5, $tops['expenses']);
        $this->assertSame(300.0, (float) $tops['expenses'][0]['amount']);

        $this->assertCount(5, $tops['membership_payments']);
        $this->assertSame(1200.0, (float) $tops['membership_payments'][0]['amount']);
        $this->assertSame('Alumno6 Test', $tops['membership_payments'][0]['student']['full_name']);

        $this->assertCount(5, $tops['attendances_by_student']);
        $this->assertSame(6, $tops['attendances_by_student'][0]['total']);
        $this->assertSame('Alumno6 Test', $tops['attendances_by_student'][0]['student']['full_name']);
        $this->assertSame(2, $tops['attendances_by_student'][4]['total']);
    }

    public function test_period_quarter_returns_months_without_tops(): void
    {
        $student = Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ]);

        MembershipPayment::query()->create([
            'student_id' => $student->id,
            'amount' => 500,
            'payment_date' => '2026-01-10',
            'period_month' => '2026-01',
            'payment_method' => 'efectivo',
        ]);
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-02-15',
            'total' => 200,
        ]);
        Expense::query()->create([
            'category' => 'Renta',
            'amount' => 100,
            'expense_date' => '2026-03-01',
            'branch_id' => $this->branch->id,
        ]);
        // Outside quarter
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-04-01',
            'total' => 999,
        ]);

        $response = $this->getJson('/api/reports/period?period=quarter&year=2026&quarter=1');

        $response->assertOk()
            ->assertJsonPath('period', 'quarter')
            ->assertJsonPath('label', 'Q1 2026')
            ->assertJsonPath('from', '2026-01-01')
            ->assertJsonPath('to', '2026-03-31')
            ->assertJsonPath('income.membership_payments', 500)
            ->assertJsonPath('income.sales', 200)
            ->assertJsonPath('income.total', 700)
            ->assertJsonPath('expenses.total', 100)
            ->assertJsonPath('balance', 600)
            ->assertJsonPath('tops', null);

        $months = $response->json('months');
        $this->assertCount(3, $months);
        $this->assertSame(1, $months[0]['month']);
        $this->assertSame(500.0, (float) $months[0]['income']['membership_payments']);
        $this->assertSame(2, $months[1]['month']);
        $this->assertSame(200.0, (float) $months[1]['income']['sales']);
        $this->assertSame(3, $months[2]['month']);
        $this->assertSame(100.0, (float) $months[2]['expenses']['total']);
    }

    public function test_period_month_includes_tops(): void
    {
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-08-05',
            'total' => 150,
        ]);

        $response = $this->getJson('/api/reports/period?period=month&year=2026&month=8');

        $response->assertOk()
            ->assertJsonPath('period', 'month')
            ->assertJsonPath('label', '08/2026')
            ->assertJsonCount(1, 'months');

        $this->assertIsArray($response->json('tops'));
        $this->assertCount(1, $response->json('tops.sales'));
    }

    public function test_period_export_returns_xlsx(): void
    {
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-01-10',
            'total' => 100,
        ]);

        $response = $this->get('/api/reports/period/export?period=year&year=2026');

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );
        $this->assertStringContainsString(
            'resumen-2026.xlsx',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_period_requires_quarter_when_quarter_period(): void
    {
        $this->getJson('/api/reports/period?period=quarter&year=2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quarter']);
    }
}
