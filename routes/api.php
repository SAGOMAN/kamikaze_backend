<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ClassScheduleController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InstructorController;
use App\Http\Controllers\Api\MembershipPaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductStockController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StudentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('branches', BranchController::class);
    Route::apiResource('students', StudentController::class);
    Route::apiResource('membership-payments', MembershipPaymentController::class);
    Route::apiResource('attendances', AttendanceController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('products', ProductController::class);
    Route::get('product-stocks', [ProductStockController::class, 'index']);
    Route::put('product-stocks', [ProductStockController::class, 'upsert']);
    Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('expenses', ExpenseController::class);
    Route::get('reports/monthly', [ReportController::class, 'monthly']);
    Route::get('reports/period', [ReportController::class, 'period']);
    Route::get('reports/period/export', [ReportController::class, 'export']);
    Route::apiResource('instructors', InstructorController::class);
    Route::apiResource('class-schedules', ClassScheduleController::class);
});
