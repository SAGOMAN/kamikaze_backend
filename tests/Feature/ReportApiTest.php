<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\ClassSchedule;
use App\Models\Expense;
use App\Models\Instructor;
use App\Models\MembershipPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
            ->assertJsonPath('expenses.operational', 1050)
            ->assertJsonPath('expenses.merchandise', 0)
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
            ->assertJsonPath('expenses.operational', 100)
            ->assertJsonPath('expenses.merchandise', 0)
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
        $product = Product::query()->create([
            'name' => 'Guantes',
            'sku' => 'GNT-1',
            'unit_price' => 150,
            'is_active' => true,
        ]);

        $sale = Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-08-05',
            'total' => 150,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 75,
            'subtotal' => 150,
        ]);

        $response = $this->getJson('/api/reports/period?period=month&year=2026&month=8');

        $response->assertOk()
            ->assertJsonPath('period', 'month')
            ->assertJsonPath('label', '08/2026')
            ->assertJsonCount(1, 'months')
            ->assertJsonPath('tops.sales.0.sale_date', '2026-08-05')
            ->assertJsonPath('tops.sales.0.items.0.product.name', 'Guantes')
            ->assertJsonPath('tops.sales.0.items.0.quantity', 2);

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

    public function test_monthly_report_splits_merchandise_and_operational_expenses(): void
    {
        Expense::query()->create([
            'category' => 'Renta',
            'amount' => 200,
            'expense_date' => '2026-09-01',
            'source' => Expense::SOURCE_OPERATIONAL,
            'branch_id' => $this->branch->id,
        ]);

        Expense::query()->create([
            'category' => Expense::CATEGORY_MERCHANDISE,
            'amount' => 80,
            'expense_date' => '2026-09-05',
            'source' => Expense::SOURCE_MERCHANDISE,
            'branch_id' => $this->branch->id,
        ]);

        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-09-10',
            'total' => 500,
        ]);

        $response = $this->getJson('/api/reports/monthly?year=2026&month=9');

        $response->assertOk()
            ->assertJsonPath('income.sales', 500)
            ->assertJsonPath('expenses.operational', 200)
            ->assertJsonPath('expenses.merchandise', 80)
            ->assertJsonPath('expenses.total', 280)
            ->assertJsonPath('balance', 220);
    }

    public function test_period_filters_by_branch_ids(): void
    {
        $norte = Branch::query()->create([
            'name' => 'Norte',
            'is_active' => true,
        ]);

        $student = Student::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'is_active' => true,
        ]);

        MembershipPayment::query()->create([
            'student_id' => $student->id,
            'amount' => 400,
            'payment_date' => '2026-08-02',
            'period_month' => '2026-08',
            'payment_method' => 'efectivo',
        ]);
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-08-05',
            'total' => 150,
        ]);
        Sale::query()->create([
            'branch_id' => $norte->id,
            'sale_date' => '2026-08-06',
            'total' => 80,
        ]);
        Expense::query()->create([
            'category' => 'Renta',
            'amount' => 50,
            'expense_date' => '2026-08-07',
            'branch_id' => $this->branch->id,
        ]);
        Expense::query()->create([
            'category' => 'Luz',
            'amount' => 30,
            'expense_date' => '2026-08-08',
            'branch_id' => $norte->id,
        ]);

        $all = $this->getJson('/api/reports/period?period=month&year=2026&month=8');
        $all->assertOk()
            ->assertJsonPath('income.membership_payments', 400)
            ->assertJsonPath('income.sales', 230)
            ->assertJsonPath('expenses.total', 80)
            ->assertJsonPath('balance', 550);

        $this->assertCount(3, $all->json('by_branch'));
        $this->assertSame('Sin sucursal', $all->json('by_branch.2.name'));

        $centroOnly = $this->getJson('/api/reports/period?'.http_build_query([
            'period' => 'month',
            'year' => 2026,
            'month' => 8,
            'branch_ids' => [$this->branch->id],
        ]));
        $centroOnly->assertOk()
            ->assertJsonPath('income.membership_payments', 0)
            ->assertJsonPath('income.sales', 150)
            ->assertJsonPath('expenses.total', 50)
            ->assertJsonPath('balance', 100)
            ->assertJsonPath('tops.membership_payments', []);

        $this->assertCount(1, $centroOnly->json('by_branch'));
        $this->assertSame('Centro', $centroOnly->json('by_branch.0.name'));

        $both = $this->getJson('/api/reports/period?'.http_build_query([
            'period' => 'month',
            'year' => 2026,
            'month' => 8,
            'branch_ids' => [$this->branch->id, $norte->id],
        ]));
        $both->assertOk()
            ->assertJsonPath('income.membership_payments', 400)
            ->assertJsonPath('income.sales', 230)
            ->assertJsonPath('expenses.total', 80);
        $this->assertGreaterThanOrEqual(2, count($both->json('by_branch')));
    }

    public function test_period_rejects_unknown_branch_id(): void
    {
        $this->getJson('/api/reports/period?period=month&year=2026&month=8&branch_ids[]=999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_ids.0']);
    }

    public function test_period_export_includes_movement_rows_and_branch_breakdown(): void
    {
        $norte = Branch::query()->create([
            'name' => 'Norte',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'first_name' => 'Luis',
            'last_name' => 'García',
            'is_active' => true,
        ]);
        MembershipPayment::query()->create([
            'student_id' => $student->id,
            'amount' => 300,
            'payment_date' => '2026-01-04',
            'period_month' => '2026-01',
            'payment_method' => 'efectivo',
        ]);
        Sale::query()->create([
            'branch_id' => $this->branch->id,
            'sale_date' => '2026-01-10',
            'total' => 100,
            'notes' => 'Guantes',
        ]);
        Sale::query()->create([
            'branch_id' => $norte->id,
            'sale_date' => '2026-01-12',
            'total' => 40,
        ]);
        Expense::query()->create([
            'category' => 'Renta',
            'description' => 'Enero',
            'amount' => 25,
            'expense_date' => '2026-01-15',
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->get('/api/reports/period/export?period=year&year=2026');
        $response->assertOk();

        $spreadsheet = $this->spreadsheetFromResponse($response->streamedContent());
        $this->assertSame(['Resumen', 'Movimientos', 'Por mes'], $spreadsheet->getSheetNames());

        $summary = $spreadsheet->getSheetByName('Resumen');
        $this->assertSame('Sucursales', $summary->getCell('A5')->getValue());
        $this->assertSame('Todas', $summary->getCell('B5')->getValue());
        $this->assertSame('Desglose por sucursal', $summary->getCell('A16')->getValue());
        $this->assertSame('Centro', $summary->getCell('A18')->getValue());
        $this->assertSame('Norte', $summary->getCell('A19')->getValue());
        $this->assertSame('Sin sucursal', $summary->getCell('A20')->getValue());
        $this->assertSame('Total', $summary->getCell('A21')->getValue());

        $movements = $spreadsheet->getSheetByName('Movimientos');
        $this->assertSame('Fecha', $movements->getCell('A1')->getValue());
        $this->assertSame('Sucursal', $movements->getCell('B1')->getValue());
        $dates = [
            (string) $movements->getCell('A2')->getValue(),
            (string) $movements->getCell('A3')->getValue(),
            (string) $movements->getCell('A4')->getValue(),
            (string) $movements->getCell('A5')->getValue(),
        ];
        $this->assertContains('2026-01-04', $dates);
        $this->assertContains('2026-01-10', $dates);
        $this->assertContains('2026-01-12', $dates);
        $this->assertContains('2026-01-15', $dates);

        $types = [
            (string) $movements->getCell('C2')->getValue(),
            (string) $movements->getCell('C3')->getValue(),
            (string) $movements->getCell('C4')->getValue(),
            (string) $movements->getCell('C5')->getValue(),
        ];
        $this->assertContains('Ganancia', $types);
        $this->assertContains('Gasto', $types);

        $filtered = $this->get('/api/reports/period/export?'.http_build_query([
            'period' => 'year',
            'year' => 2026,
            'branch_ids' => [$norte->id],
        ]));
        $filteredSheet = $this->spreadsheetFromResponse($filtered->streamedContent())->getSheetByName('Movimientos');
        $this->assertSame('2026-01-12', (string) $filteredSheet->getCell('A2')->getValue());
        $this->assertSame('Norte', (string) $filteredSheet->getCell('B2')->getValue());
        $this->assertSame('', (string) $filteredSheet->getCell('A3')->getValue());
    }

    private function spreadsheetFromResponse(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $spreadsheet = IOFactory::load($path);
        unlink($path);

        return $spreadsheet;
    }
}
