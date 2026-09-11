<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CatalogItemController;
use App\Http\Controllers\Api\ClassScheduleController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InstructorController;
use App\Http\Controllers\Api\MembershipPaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductStockController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);

    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::put('users/{user}/password', [UserController::class, 'updatePassword']);

    Route::apiResource('branches', BranchController::class);
    Route::apiResource('students', StudentController::class);
    Route::apiResource('membership-payments', MembershipPaymentController::class);
    Route::apiResource('attendances', AttendanceController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('products', ProductController::class);
    Route::get('product-stocks', [ProductStockController::class, 'index']);
    Route::put('product-stocks', [ProductStockController::class, 'upsert']);
    Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('purchases', [PurchaseController::class, 'store']);
    Route::apiResource('expenses', ExpenseController::class);
    Route::get('reports/monthly', [ReportController::class, 'monthly']);
    Route::get('reports/period', [ReportController::class, 'period']);
    Route::get('reports/period/export', [ReportController::class, 'export']);
    Route::apiResource('catalogs', CatalogController::class);
    Route::get('catalogs/{catalog}/items', [CatalogItemController::class, 'index']);
    Route::post('catalogs/{catalog}/items', [CatalogItemController::class, 'store']);
    Route::put('catalog-items/{catalogItem}', [CatalogItemController::class, 'update']);
    Route::delete('catalog-items/{catalogItem}', [CatalogItemController::class, 'destroy']);

    Route::apiResource('instructors', InstructorController::class);
    Route::apiResource('class-schedules', ClassScheduleController::class);
});
