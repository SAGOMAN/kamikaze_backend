<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\MembershipPayment;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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
            'branch_ids' => $this->validatedBranchIds($request),
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
        $this->fillSummarySheet($spreadsheet->getActiveSheet(), $report);
        $this->fillMovementsSheet($spreadsheet->createSheet(), $report);
        $this->fillByMonthSheet($spreadsheet->createSheet(), $report);
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
            'branch_ids' => $this->validatedBranchIds($request),
        ];
    }

    /**
     * @return list<int>|null
     */
    private function validatedBranchIds(Request $request): ?array
    {
        $raw = $request->input('branch_ids', $request->query('branch_ids'));

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if (is_string($raw)) {
            $raw = array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $v) => $v !== ''));
        }

        if (! is_array($raw)) {
            $raw = [$raw];
        }

        $ids = array_values(array_unique(array_map('intval', $raw)));

        validator(
            ['branch_ids' => $ids],
            [
                'branch_ids' => ['required', 'array', 'min:1'],
                'branch_ids.*' => ['integer', 'exists:branches,id'],
            ]
        )->validate();

        return $ids;
    }

    /**
     * @param  array{period: string, year: int, month?: int|null, quarter?: int|null, semester?: int|null, branch_ids?: list<int>|null}  $params
     * @return array<string, mixed>
     */
    private function buildPeriodReport(array $params): array
    {
        $resolved = $this->resolveRange($params);
        $from = $resolved['from'];
        $to = $resolved['to'];
        $branchIds = $params['branch_ids'] ?? null;
        $includeUnassigned = $this->includeUnassignedMovements($branchIds);
        $totals = $this->periodTotals($from, $to, $branchIds, $includeUnassigned);

        $tops = null;
        if ($params['period'] === 'month') {
            $tops = [
                'sales' => $this->topSales($from, $to, $branchIds),
                'expenses' => $this->topExpenses($from, $to, $branchIds, $includeUnassigned),
                'membership_payments' => $includeUnassigned
                    ? $this->topMembershipPayments($from, $to)
                    : [],
                'attendances_by_student' => $this->topAttendancesByStudent($from, $to, $branchIds),
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
            'sucursales_label' => $this->sucursalesLabel($branchIds),
            'income' => $totals['income'],
            'expenses' => $totals['expenses'],
            'balance' => $totals['balance'],
            'months' => $totals['months'],
            'by_branch' => $totals['by_branch'],
            'tops' => $tops,
            'branch_ids' => $branchIds,
            'include_unassigned' => $includeUnassigned,
        ];
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function includeUnassignedMovements(?array $branchIds): bool
    {
        if ($branchIds === null) {
            return true;
        }

        return ! Branch::query()->whereNotIn('id', $branchIds)->exists();
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function sucursalesLabel(?array $branchIds): string
    {
        if ($branchIds === null) {
            return 'Todas';
        }

        $names = Branch::query()
            ->whereIn('id', $branchIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return implode(', ', $names);
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
     * @param  list<int>|null  $branchIds
     * @return array{income: array<string, float>, expenses: array<string, float>, balance: float, months: list<array<string, mixed>>, by_branch: list<array<string, mixed>>}
     */
    private function periodTotals(string $from, string $to, ?array $branchIds, bool $includeUnassigned): array
    {
        $monthBuckets = [];
        $branchBuckets = [];

        $cursor = strtotime(date('Y-m-01', strtotime($from)));
        $end = strtotime(date('Y-m-01', strtotime($to)));
        while ($cursor <= $end) {
            $monthBuckets[sprintf('%04d-%02d', (int) date('Y', $cursor), (int) date('n', $cursor))] = $this->emptyBucket();
            $cursor = strtotime('+1 month', $cursor);
        }

        $branchesQuery = Branch::query()->orderBy('name');
        if ($branchIds !== null) {
            $branchesQuery->whereIn('id', $branchIds);
        }
        $branches = $branchesQuery->get(['id', 'name']);
        foreach ($branches as $branch) {
            $branchBuckets[$branch->id] = $this->emptyBucket();
        }
        $branchBuckets['none'] = $this->emptyBucket();

        if ($includeUnassigned) {
            MembershipPayment::query()
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->get(['payment_date', 'amount'])
                ->each(function (MembershipPayment $row) use (&$monthBuckets, &$branchBuckets) {
                    $monthKey = $row->payment_date?->format('Y-m');
                    $amount = (float) $row->amount;
                    $this->addToBucket($monthBuckets, $monthKey, 'membership', $amount);
                    $this->addToBucket($branchBuckets, 'none', 'membership', $amount);
                });
        }

        $salesQuery = Sale::query()
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to);
        $this->constrainByBranch($salesQuery, $branchIds);
        $salesQuery
            ->get(['sale_date', 'total', 'branch_id'])
            ->each(function (Sale $row) use (&$monthBuckets, &$branchBuckets) {
                $monthKey = $row->sale_date?->format('Y-m');
                $amount = (float) $row->total;
                $this->addToBucket($monthBuckets, $monthKey, 'sale', $amount);
                $this->addToBucket($branchBuckets, $row->branch_id, 'sale', $amount);
            });

        $expensesQuery = Expense::query()
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to);
        $this->constrainExpensesByBranch($expensesQuery, $branchIds, $includeUnassigned);
        $expensesQuery
            ->get(['expense_date', 'amount', 'source', 'branch_id'])
            ->each(function (Expense $row) use (&$monthBuckets, &$branchBuckets) {
                $monthKey = $row->expense_date?->format('Y-m');
                $amount = (float) $row->amount;
                $kind = $row->source === Expense::SOURCE_MERCHANDISE ? 'merchandise' : 'operational';
                $this->addToBucket($monthBuckets, $monthKey, $kind, $amount);
                $this->addToBucket($branchBuckets, $row->branch_id ?? 'none', $kind, $amount);
            });

        $months = [];
        foreach ($monthBuckets as $key => $bucket) {
            [$year, $month] = array_map('intval', explode('-', (string) $key));
            $months[] = array_merge(['year' => $year, 'month' => $month], $this->finalizeBucket($bucket));
        }

        $byBranch = [];
        foreach ($branches as $branch) {
            $byBranch[] = array_merge(
                ['id' => $branch->id, 'name' => $branch->name],
                $this->finalizeBucket($branchBuckets[$branch->id] ?? $this->emptyBucket())
            );
        }

        $unassigned = $this->finalizeBucket($branchBuckets['none']);
        $hasUnassigned = $unassigned['income']['total'] != 0.0 || $unassigned['expenses']['total'] != 0.0;
        if ($includeUnassigned && $hasUnassigned) {
            $byBranch[] = array_merge(['id' => null, 'name' => 'Sin sucursal'], $unassigned);
        }

        $incomeTotal = array_sum(array_map(fn (array $m) => $m['income']['total'], $months));
        $membershipTotal = array_sum(array_map(fn (array $m) => $m['income']['membership_payments'], $months));
        $salesTotal = array_sum(array_map(fn (array $m) => $m['income']['sales'], $months));
        $expensesTotal = array_sum(array_map(fn (array $m) => $m['expenses']['total'], $months));
        $merchandiseTotal = array_sum(array_map(fn (array $m) => $m['expenses']['merchandise'], $months));
        $operationalTotal = array_sum(array_map(fn (array $m) => $m['expenses']['operational'], $months));

        return [
            'income' => [
                'membership_payments' => round($membershipTotal, 2),
                'sales' => round($salesTotal, 2),
                'total' => round($incomeTotal, 2),
            ],
            'expenses' => [
                'total' => round($expensesTotal, 2),
                'merchandise' => round($merchandiseTotal, 2),
                'operational' => round($operationalTotal, 2),
            ],
            'balance' => round($incomeTotal - $expensesTotal, 2),
            'months' => $months,
            'by_branch' => $byBranch,
        ];
    }

    /**
     * @return array{income: array{membership_payments: float, sales: float, total: float}, expenses: array{total: float, merchandise: float, operational: float}, balance: float}
     */
    private function emptyBucket(): array
    {
        return [
            'income' => ['membership_payments' => 0.0, 'sales' => 0.0, 'total' => 0.0],
            'expenses' => ['total' => 0.0, 'merchandise' => 0.0, 'operational' => 0.0],
            'balance' => 0.0,
        ];
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $buckets
     */
    private function addToBucket(array &$buckets, string|int|null $key, string $kind, float $amount): void
    {
        if ($key === null || $key === '') {
            return;
        }
        if (! isset($buckets[$key])) {
            $buckets[$key] = $this->emptyBucket();
        }

        match ($kind) {
            'membership' => $buckets[$key]['income']['membership_payments'] += $amount,
            'sale' => $buckets[$key]['income']['sales'] += $amount,
            'merchandise' => $buckets[$key]['expenses']['merchandise'] += $amount,
            'operational' => $buckets[$key]['expenses']['operational'] += $amount,
            default => null,
        };
    }

    /**
     * @param  array{income: array{membership_payments: float, sales: float, total: float}, expenses: array{total: float, merchandise: float, operational: float}, balance: float}  $bucket
     * @return array{income: array{membership_payments: float, sales: float, total: float}, expenses: array{total: float, merchandise: float, operational: float}, balance: float}
     */
    private function finalizeBucket(array $bucket): array
    {
        $membership = (float) $bucket['income']['membership_payments'];
        $sales = (float) $bucket['income']['sales'];
        $merchandise = (float) $bucket['expenses']['merchandise'];
        $operational = (float) $bucket['expenses']['operational'];
        $incomeTotal = $membership + $sales;
        $expenseTotal = $merchandise + $operational;

        return [
            'income' => [
                'membership_payments' => round($membership, 2),
                'sales' => round($sales, 2),
                'total' => round($incomeTotal, 2),
            ],
            'expenses' => [
                'total' => round($expenseTotal, 2),
                'merchandise' => round($merchandise, 2),
                'operational' => round($operational, 2),
            ],
            'balance' => round($incomeTotal - $expenseTotal, 2),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<int>|null  $branchIds
     */
    private function constrainByBranch($query, ?array $branchIds): void
    {
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<int>|null  $branchIds
     */
    private function constrainExpensesByBranch($query, ?array $branchIds, bool $includeUnassigned): void
    {
        if ($branchIds === null) {
            return;
        }

        $query->where(function ($q) use ($branchIds, $includeUnassigned) {
            $q->whereIn('branch_id', $branchIds);
            if ($includeUnassigned) {
                $q->orWhereNull('branch_id');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function fillSummarySheet($sheet, array $report): void
    {
        $sheet->setTitle('Resumen');
        $sheet->fromArray([
            ['Campo', 'Valor'],
            ['Período', $report['label']],
            ['Desde', $report['from']],
            ['Hasta', $report['to']],
            ['Sucursales', $report['sucursales_label']],
            [],
            ['Totales combinados', ''],
            ['Mensualidades', $report['income']['membership_payments']],
            ['Ventas', $report['income']['sales']],
            ['Ingresos totales', $report['income']['total']],
            ['Gastos mercancía', $report['expenses']['merchandise']],
            ['Gastos operativos', $report['expenses']['operational']],
            ['Gastos', $report['expenses']['total']],
            ['Balance', $report['balance']],
        ], null, 'A1');

        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A7')->getFont()->setBold(true);

        $byBranch = $report['by_branch'];
        $branchRows = array_values(array_filter(
            $byBranch,
            fn (array $row) => $row['id'] !== null
        ));
        $showBreakdown = count($branchRows) > 1 || (count($byBranch) > 1);

        if ($showBreakdown) {
            $start = 16;
            $sheet->fromArray([['Desglose por sucursal']], null, "A{$start}");
            $sheet->getStyle("A{$start}")->getFont()->setBold(true);
            $headerRow = $start + 1;
            $sheet->fromArray([[
                'Sucursal',
                'Mensualidades',
                'Ventas',
                'Ingresos',
                'Mercancía',
                'Operativos',
                'Gastos',
                'Balance',
            ]], null, "A{$headerRow}");
            $sheet->getStyle("A{$headerRow}:H{$headerRow}")->getFont()->setBold(true);

            $row = $headerRow + 1;
            foreach ($byBranch as $branch) {
                $sheet->fromArray([[
                    $branch['name'],
                    $branch['income']['membership_payments'],
                    $branch['income']['sales'],
                    $branch['income']['total'],
                    $branch['expenses']['merchandise'],
                    $branch['expenses']['operational'],
                    $branch['expenses']['total'],
                    $branch['balance'],
                ]], null, "A{$row}");
                $row++;
            }

            $sheet->fromArray([[
                'Total',
                $report['income']['membership_payments'],
                $report['income']['sales'],
                $report['income']['total'],
                $report['expenses']['merchandise'],
                $report['expenses']['operational'],
                $report['expenses']['total'],
                $report['balance'],
            ]], null, "A{$row}");
            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function fillMovementsSheet($sheet, array $report): void
    {
        $sheet->setTitle('Movimientos');
        $sheet->fromArray([[
            'Fecha',
            'Sucursal',
            'Tipo',
            'Categoría',
            'Descripción',
            'Monto',
            'Usuario',
        ]], null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        $rows = $this->movementRows(
            $report['from'],
            $report['to'],
            $report['branch_ids'] ?? null,
            (bool) $report['include_unassigned']
        );

        $row = 2;
        foreach ($rows as $movement) {
            $sheet->fromArray([[
                $movement['date'],
                $movement['branch'],
                $movement['type'],
                $movement['category'],
                $movement['description'],
                $movement['amount'],
                $movement['user'],
            ]], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function fillByMonthSheet($sheet, array $report): void
    {
        $sheet->setTitle('Por mes');
        $sheet->fromArray([
            ['Año', 'Mes', 'Mensualidades', 'Ventas', 'Ingresos', 'Mercancía', 'Operativos', 'Gastos', 'Balance'],
        ], null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        $row = 2;
        foreach ($report['months'] as $month) {
            $sheet->fromArray([
                [
                    $month['year'],
                    $month['month'],
                    $month['income']['membership_payments'],
                    $month['income']['sales'],
                    $month['income']['total'],
                    $month['expenses']['merchandise'],
                    $month['expenses']['operational'],
                    $month['expenses']['total'],
                    $month['balance'],
                ],
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<array{date: string, branch: string, type: string, category: string, description: string, amount: float, user: string}>
     */
    private function movementRows(string $from, string $to, ?array $branchIds, bool $includeUnassigned): array
    {
        $rows = [];

        if ($includeUnassigned) {
            MembershipPayment::query()
                ->with(['student:id,first_name,last_name', 'createdBy:id,name'])
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->orderBy('payment_date')
                ->orderBy('id')
                ->get()
                ->each(function (MembershipPayment $payment) use (&$rows) {
                    $student = $payment->student
                        ? trim($payment->student->first_name.' '.$payment->student->last_name)
                        : 'Alumno';
                    $desc = $student.' · período '.$payment->period_month;
                    if ($payment->notes) {
                        $desc .= ' · '.$payment->notes;
                    }

                    $rows[] = [
                        'date' => $payment->payment_date?->toDateString() ?? '',
                        'branch' => 'Sin sucursal',
                        'type' => 'Ganancia',
                        'category' => 'Mensualidad',
                        'description' => $desc,
                        'amount' => round((float) $payment->amount, 2),
                        'user' => $payment->createdBy?->name ?? '',
                        'sort' => ($payment->payment_date?->toDateString() ?? '').'-1-'.$payment->id,
                    ];
                });
        }

        $salesQuery = Sale::query()
            ->with(['branch:id,name', 'createdBy:id,name', 'items.product:id,name'])
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to);
        $this->constrainByBranch($salesQuery, $branchIds);
        $salesQuery
            ->orderBy('sale_date')
            ->orderBy('id')
            ->get()
            ->each(function (Sale $sale) use (&$rows) {
                $itemDesc = $sale->items
                    ->map(function ($item) {
                        $name = $item->product?->name ?? 'Producto';

                        return $name.' ×'.$item->quantity;
                    })
                    ->filter()
                    ->implode(', ');
                $desc = trim((string) $sale->notes) !== '' ? (string) $sale->notes : $itemDesc;

                $rows[] = [
                    'date' => $sale->sale_date?->toDateString() ?? '',
                    'branch' => $sale->branch?->name ?? 'Sin sucursal',
                    'type' => 'Ganancia',
                    'category' => 'Venta',
                    'description' => $desc,
                    'amount' => round((float) $sale->total, 2),
                    'user' => $sale->createdBy?->name ?? '',
                    'sort' => ($sale->sale_date?->toDateString() ?? '').'-2-'.$sale->id,
                ];
            });

        $expensesQuery = Expense::query()
            ->with(['branch:id,name', 'createdBy:id,name', 'product:id,name'])
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to);
        $this->constrainExpensesByBranch($expensesQuery, $branchIds, $includeUnassigned);
        $expensesQuery
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get()
            ->each(function (Expense $expense) use (&$rows) {
                $parts = array_filter([
                    $expense->description,
                    $expense->product?->name,
                    $expense->notes,
                ]);

                $rows[] = [
                    'date' => $expense->expense_date?->toDateString() ?? '',
                    'branch' => $expense->branch?->name ?? 'Sin sucursal',
                    'type' => 'Gasto',
                    'category' => $expense->category,
                    'description' => implode(' · ', $parts),
                    'amount' => round((float) $expense->amount, 2),
                    'user' => $expense->createdBy?->name ?? '',
                    'sort' => ($expense->expense_date?->toDateString() ?? '').'-3-'.$expense->id,
                ];
            });

        usort($rows, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        return array_map(function (array $row) {
            unset($row['sort']);

            return $row;
        }, $rows);
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
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private function topSales(string $from, string $to, ?array $branchIds): array
    {
        $query = Sale::query()
            ->with('branch:id,name')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to);
        $this->constrainByBranch($query, $branchIds);

        return $query
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
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private function topExpenses(string $from, string $to, ?array $branchIds, bool $includeUnassigned): array
    {
        $query = Expense::query()
            ->with('branch:id,name')
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to);
        $this->constrainExpensesByBranch($query, $branchIds, $includeUnassigned);

        return $query
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
     * @param  list<int>|null  $branchIds
     * @return list<array<string, mixed>>
     */
    private function topAttendancesByStudent(string $from, string $to, ?array $branchIds): array
    {
        $query = Attendance::query()
            ->select('student_id', DB::raw('COUNT(*) as total'))
            ->with('student:id,first_name,last_name')
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);
        $this->constrainByBranch($query, $branchIds);

        return $query
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
