<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\MembershipPayment;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function monthly(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));

        $membershipIncome = (float) MembershipPayment::query()
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to)
            ->sum('amount');

        $salesIncome = (float) Sale::query()
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->sum('total');

        $expensesTotal = (float) Expense::query()
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->sum('amount');

        $incomeTotal = $membershipIncome + $salesIncome;
        $balance = $incomeTotal - $expensesTotal;

        return response()->json([
            'year' => $year,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'income' => [
                'membership_payments' => $membershipIncome,
                'sales' => $salesIncome,
                'total' => $incomeTotal,
            ],
            'expenses' => [
                'total' => $expensesTotal,
            ],
            'balance' => $balance,
        ]);
    }
}
