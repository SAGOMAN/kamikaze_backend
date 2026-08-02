<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\MembershipPayment;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function monthly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $report = $this->buildPeriodReport([
            'period' => 'month',
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
        ]);

        // Contrato legado del endpoint mensual.
        return response()->json([
            'year' => $report['year'],
            'month' => $report['month'],
            'from' => $report['from'],
            'to' => $report['to'],
            'income' => $report['income'],
            'expenses' => $report['expenses'],
            'balance' => $report['balance'],
            'tops' => $report['tops'],
        ]);
    }

    public function period(Request $request): JsonResponse
    {
        return response()->json($this->buildPeriodReport($this->validatedPeriodParams($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $report = $this->buildPeriodReport($this->validatedPeriodParams($request));
        $filename = $this->exportFilename($report);

        $spreadsheet = new Spreadsheet();

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Resumen');
        $summary->fromArray([
            ['Campo', 'Valor'],
            ['Período', $report['label']],
            ['Desde', $report['from']],
            ['Hasta', $report['to']],
            ['Mensualidades', $report['income']['membership_payments']],
            ['Ventas', $report['income']['sales']],
            ['Ingresos totales', $report['income']['total']],
            ['Gastos', $report['expenses']['total']],
            ['Balance', $report['balance']],
        ], null, 'A1');

        $byMonth = $spreadsheet->createSheet();
        $byMonth->setTitle('Por mes');
        $byMonth->fromArray([
            ['Año', 'Mes', 'Mensualidades', 'Ventas', 'Ingresos', 'Gastos', 'Balance'],
        ], null, 'A1');

        $row = 2;
        foreach ($report['months'] as $month) {
            $byMonth->fromArray([
                [
                    $month['year'],
                    $month['month'],
                    $month['income']['membership_payments'],
                    $month['income']['sales'],
                    $month['income']['total'],
                    $month['expenses']['total'],
                    $month['balance'],
                ],
            ], null, "A{$row}");
            $row++;
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPeriodParams(Request $request): array
    {
        $data = $request->validate([
            'period' => ['required', Rule::in(['month', 'quarter', 'semester', 'year'])],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'quarter' => ['nullable', 'integer', 'min:1', 'max:4'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:2'],
        ]);

        $period = $data['period'];

        if ($period === 'month' && empty($data['month'])) {
            throw ValidationException::withMessages(['month' => 'El mes es obligatorio.']);
        }
        if ($period === 'quarter' && empty($data['quarter'])) {
            throw ValidationException::withMessages(['quarter' => 'El trimestre es obligatorio.']);
        }
        if ($period === 'semester' && empty($data['semester'])) {
            throw ValidationException::withMessages(['semester' => 'El semestre es obligatorio.']);
        }

        return [
            'period' => $period,
            'year' => (int) $data['year'],
            'month' => isset($data['month']) ? (int) $data['month'] : null,
            'quarter' => isset($data['quarter']) ? (int) $data['quarter'] : null,
            'semester' => isset($data['semester']) ? (int) $data['semester'] : null,
        ];
    }

    /**
     * @param  array{period: string, year: int, month?: int|null, quarter?: int|null, semester?: int|null}  $params
     * @return array<string, mixed>
     */
    private function buildPeriodReport(array $params): array
    {
        $resolved = $this->resolveRange($params);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $months = $this->monthlyBreakdown($from, $to);

        $membershipIncome = array_sum(array_column(array_column($months, 'income'), 'membership_payments'));
        $salesIncome = array_sum(array_column(array_column($months, 'income'), 'sales'));
        $expensesTotal = array_sum(array_map(fn (array $m) => $m['expenses']['total'], $months));
        $incomeTotal = $membershipIncome + $salesIncome;
        $balance = $incomeTotal - $expensesTotal;

        $tops = null;
        if ($params['period'] === 'month') {
            $tops = [
                'sales' => $this->topSales($from, $to),
                'expenses' => $this->topExpenses($from, $to),
                'membership_payments' => $this->topMembershipPayments($from, $to),
                'attendances_by_student' => $this->topAttendancesByStudent($from, $to),
            ];
        }

        return [
            'period' => $params['period'],
            'year' => $params['year'],
            'month' => $params['month'] ?? null,
            'quarter' => $params['quarter'] ?? null,
            'semester' => $params['semester'] ?? null,
            'from' => $from,
            'to' => $to,
            'label' => $resolved['label'],
            'income' => [
                'membership_payments' => round($membershipIncome, 2),
                'sales' => round($salesIncome, 2),
                'total' => round($incomeTotal, 2),
            ],
            'expenses' => [
                'total' => round($expensesTotal, 2),
            ],
            'balance' => round($balance, 2),
            'months' => $months,
            'tops' => $tops,
        ];
    }

    /**
     * @param  array{period: string, year: int, month?: int|null, quarter?: int|null, semester?: int|null}  $params
     * @return array{from: string, to: string, label: string}
     */
    private function resolveRange(array $params): array
    {
        $year = $params['year'];

        return match ($params['period']) {
            'month' => (function () use ($year, $params) {
                $month = (int) $params['month'];
                $from = sprintf('%04d-%02d-01', $year, $month);
                $to = date('Y-m-t', strtotime($from));
                $label = sprintf('%02d/%04d', $month, $year);

                return compact('from', 'to', 'label');
            })(),
            'quarter' => (function () use ($year, $params) {
                $quarter = (int) $params['quarter'];
                $startMonth = ($quarter - 1) * 3 + 1;
                $endMonth = $startMonth + 2;
                $from = sprintf('%04d-%02d-01', $year, $startMonth);
                $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
                $label = sprintf('Q%d %d', $quarter, $year);

                return compact('from', 'to', 'label');
            })(),
            'semester' => (function () use ($year, $params) {
                $semester = (int) $params['semester'];
                $startMonth = $semester === 1 ? 1 : 7;
                $endMonth = $semester === 1 ? 6 : 12;
                $from = sprintf('%04d-%02d-01', $year, $startMonth);
                $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
                $label = sprintf('S%d %d', $semester, $year);

                return compact('from', 'to', 'label');
            })(),
            'year' => [
                'from' => sprintf('%04d-01-01', $year),
                'to' => sprintf('%04d-12-31', $year),
                'label' => (string) $year,
            ],
            default => throw ValidationException::withMessages(['period' => 'Período no válido.']),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function monthlyBreakdown(string $from, string $to): array
    {
        $memberships = [];
        MembershipPayment::query()
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->get(['payment_date', 'amount'])
            ->each(function (MembershipPayment $row) use (&$memberships) {
                $key = $row->payment_date?->format('Y-m');
                if ($key === null) {
                    return;
                }
                $memberships[$key] = ($memberships[$key] ?? 0) + (float) $row->amount;
            });

        $sales = [];
        Sale::query()
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->get(['sale_date', 'total'])
            ->each(function (Sale $row) use (&$sales) {
                $key = $row->sale_date?->format('Y-m');
                if ($key === null) {
                    return;
                }
                $sales[$key] = ($sales[$key] ?? 0) + (float) $row->total;
            });

        $expenses = [];
        Expense::query()
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->get(['expense_date', 'amount'])
            ->each(function (Expense $row) use (&$expenses) {
                $key = $row->expense_date?->format('Y-m');
                if ($key === null) {
                    return;
                }
                $expenses[$key] = ($expenses[$key] ?? 0) + (float) $row->amount;
            });

        $months = [];
        $cursor = strtotime(date('Y-m-01', strtotime($from)));
        $end = strtotime(date('Y-m-01', strtotime($to)));

        while ($cursor <= $end) {
            $year = (int) date('Y', $cursor);
            $month = (int) date('n', $cursor);
            $key = sprintf('%04d-%02d', $year, $month);

            $membership = (float) ($memberships[$key] ?? 0);
            $sale = (float) ($sales[$key] ?? 0);
            $expense = (float) ($expenses[$key] ?? 0);
            $incomeTotal = $membership + $sale;

            $months[] = [
                'year' => $year,
                'month' => $month,
                'income' => [
                    'membership_payments' => round($membership, 2),
                    'sales' => round($sale, 2),
                    'total' => round($incomeTotal, 2),
                ],
                'expenses' => [
                    'total' => round($expense, 2),
                ],
                'balance' => round($incomeTotal - $expense, 2),
            ];

            $cursor = strtotime('+1 month', $cursor);
        }

        return $months;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exportFilename(array $report): string
    {
        $slug = preg_replace('/[^A-Za-z0-9\-]+/', '-', (string) $report['label']) ?: 'periodo';
        $slug = trim($slug, '-');

        return "resumen-{$slug}.xlsx";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topSales(string $from, string $to): array
    {
        return Sale::query()
            ->with('branch:id,name')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->orderByDesc('total')
            ->orderByDesc('sale_date')
            ->limit(5)
            ->get(['id', 'branch_id', 'sale_date', 'total', 'notes'])
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'sale_date' => $sale->sale_date?->toDateString(),
                'total' => (float) $sale->total,
                'notes' => $sale->notes,
                'branch' => $sale->branch
                    ? ['id' => $sale->branch->id, 'name' => $sale->branch->name]
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topExpenses(string $from, string $to): array
    {
        return Expense::query()
            ->with('branch:id,name')
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->orderByDesc('amount')
            ->orderByDesc('expense_date')
            ->limit(5)
            ->get(['id', 'category', 'description', 'amount', 'expense_date', 'branch_id'])
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'category' => $expense->category,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date?->toDateString(),
                'branch' => $expense->branch
                    ? ['id' => $expense->branch->id, 'name' => $expense->branch->name]
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topMembershipPayments(string $from, string $to): array
    {
        return MembershipPayment::query()
            ->with('student:id,first_name,last_name')
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->orderByDesc('amount')
            ->orderByDesc('payment_date')
            ->limit(5)
            ->get(['id', 'student_id', 'amount', 'payment_date', 'period_month', 'payment_method'])
            ->map(fn (MembershipPayment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'payment_date' => $payment->payment_date?->toDateString(),
                'period_month' => $payment->period_month,
                'payment_method' => $payment->payment_method,
                'student' => $payment->student
                    ? [
                        'id' => $payment->student->id,
                        'first_name' => $payment->student->first_name,
                        'last_name' => $payment->student->last_name,
                        'full_name' => $payment->student->full_name,
                    ]
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topAttendancesByStudent(string $from, string $to): array
    {
        return Attendance::query()
            ->select('student_id', DB::raw('COUNT(*) as total'))
            ->with('student:id,first_name,last_name')
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->groupBy('student_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn (Attendance $row) => [
                'student_id' => $row->student_id,
                'total' => (int) $row->total,
                'student' => $row->student
                    ? [
                        'id' => $row->student->id,
                        'first_name' => $row->student->first_name,
                        'last_name' => $row->student->last_name,
                        'full_name' => $row->student->full_name,
                    ]
                    : null,
            ])
            ->values()
            ->all();
    }
}
