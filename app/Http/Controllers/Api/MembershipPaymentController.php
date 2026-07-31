<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\MembershipPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipPaymentController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $query = MembershipPayment::query()
            ->with('student')
            ->orderByDesc('payment_date');

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->query('student_id'));
        }

        if ($request->filled('period_month')) {
            $query->where('period_month', $request->query('period_month'));
        }

        if ($request->filled('from')) {
            $query->whereDate('payment_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('payment_date', '<=', $request->query('to'));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('notes', 'like', $like)
                ->orWhere('payment_method', 'like', $like)
                ->orWhere('period_month', 'like', $like)
                ->orWhereHas('student', function ($s) use ($like) {
                    $s->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('nickname', 'like', $like);
                });
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_date' => ['required', 'date'],
            'period_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = MembershipPayment::query()->create($data);

        return response()->json($payment->load('student'), 201);
    }

    public function show(MembershipPayment $membershipPayment): JsonResponse
    {
        return response()->json($membershipPayment->load('student'));
    }

    public function update(Request $request, MembershipPayment $membershipPayment): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['sometimes', 'exists:students,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'payment_date' => ['sometimes', 'date'],
            'period_month' => ['sometimes', 'regex:/^\d{4}-\d{2}$/'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $membershipPayment->update($data);

        return response()->json($membershipPayment->load('student'));
    }

    public function destroy(MembershipPayment $membershipPayment): JsonResponse
    {
        $membershipPayment->delete();

        return response()->json(null, 204);
    }
}
