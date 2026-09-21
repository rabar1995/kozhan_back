<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountTypeController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeDealController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\RemittanceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'audit'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);

    Route::get('/currencies', [CurrencyController::class, 'index']);
    Route::get('/account-types', [AccountTypeController::class, 'index']);

    Route::get('/accounts/balances', [AccountController::class, 'balances']);
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::get('/accounts/{id}/ledger', [AccountController::class, 'ledger']);

    Route::get('/agents', [AgentController::class, 'index']);
    Route::post('/uploads/logo', [UploadController::class, 'store']);
    Route::post('/agents', [AgentController::class, 'store']);
    Route::patch('/agents/{id}', [AgentController::class, 'update']);
    Route::get('/agents/{id}/ledger', [AgentController::class, 'ledger']);
    Route::get('/remittances/pending', [RemittanceController::class, 'pending']);
    Route::post('/remittances/incoming', [RemittanceController::class, 'createIncoming']);
    Route::post('/remittances/outgoing', [RemittanceController::class, 'createOutgoing']);
    Route::get('/remittances', [RemittanceController::class, 'index']);
    Route::get('/remittances/{id}', [RemittanceController::class, 'show']);
    Route::patch('/remittances/{id}/complete', [RemittanceController::class, 'complete']);

    Route::get('/expenses/categories', [ExpenseController::class, 'categories']);
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);

    Route::middleware('role:owner')->group(function () {
        Route::post('/accounts', [AccountController::class, 'store']);
        Route::put('/accounts/{id}', [AccountController::class, 'update']);
        Route::patch('/accounts/{id}/deactivate', [AccountController::class, 'deactivate']);
        Route::patch('/accounts/{id}/activate', [AccountController::class, 'activate']);
        Route::delete('/accounts/{id}', [AccountController::class, 'destroy']);

        Route::patch('/remittances/{id}/cancel', [RemittanceController::class, 'cancel']);

        Route::patch('/expenses/{id}/void', [ExpenseController::class, 'void']);

        Route::post('/transfers', [TransferController::class, 'store']);
        Route::get('/transfers', [TransferController::class, 'index']);

        Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::patch('/users/{id}/deactivate', [UserController::class, 'deactivate']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::prefix('exchange-deals')->group(function () {
            Route::get('/form-options', [ExchangeDealController::class, 'formOptions']);
            Route::get('/summary', [ExchangeDealController::class, 'summary']);
            Route::get('/', [ExchangeDealController::class, 'index']);
            Route::post('/', [ExchangeDealController::class, 'openDeal']);
            Route::post('/{id}/settle', [ExchangeDealController::class, 'settleDeal']);
            Route::patch('/{id}/cancel', [ExchangeDealController::class, 'cancel']);
        });
    });
});
