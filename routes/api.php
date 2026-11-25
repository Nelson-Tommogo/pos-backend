<?php

use App\Http\AdministrationController;
use App\Http\AuthController;
use App\Http\DirectoryController;
use App\Http\ImportController;
use App\Http\InventoryController;
use App\Http\PaymentsController;
use App\Http\PurchasesController;
use App\Http\ReportsController;
use App\Http\SalesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgot']);
        Route::post('auth/reset-password', [AuthController::class, 'reset']);
    });

    Route::middleware(['auth:sanctum', 'throttle:180,1'])->group(function (): void {
        Route::get('me', fn (Request $request) => $request->user());
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('shops', [AdministrationController::class, 'shops']);
        Route::post('shops', [AdministrationController::class, 'onboard']);
        Route::get('settings', [AdministrationController::class, 'settings']);
        Route::patch('settings', [AdministrationController::class, 'updateSettings']);
        Route::patch('preferences', [AdministrationController::class, 'preferences']);
        Route::get('team/{id?}', [AdministrationController::class, 'team'])->whereNumber('id');
        Route::get('users/{id?}', [AdministrationController::class, 'team'])->whereNumber('id');
        Route::post('users/invitations', [AdministrationController::class, 'invite'])->middleware('throttle:10,1');
        Route::post('users', [AdministrationController::class, 'user']);
        Route::patch('users/{id}', [AdministrationController::class, 'user'])->whereNumber('id');

        Route::get('{resource}/template', [ImportController::class, 'template'])->whereIn('resource', ['products', 'users']);
        Route::post('{resource}/import', [ImportController::class, 'import'])->whereIn('resource', ['products', 'users']);

        Route::get('inventory', [InventoryController::class, 'index']);
        Route::get('inventory/movements', [InventoryController::class, 'movements']);
        Route::get('inventory/{branch}/{product}', [InventoryController::class, 'show'])->whereNumber(['branch', 'product']);
        Route::put('inventory/{branch}/{product}', [InventoryController::class, 'configure'])->whereNumber(['branch', 'product']);
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust']);
        Route::post('inventory/transfers', [InventoryController::class, 'transfer']);

        Route::get('purchases/{id?}', [PurchasesController::class, 'index'])->whereNumber('id');
        Route::post('purchases', [PurchasesController::class, 'save']);
        Route::patch('purchases/{id}', [PurchasesController::class, 'save'])->whereNumber('id');
        Route::post('purchases/{id}/receive', [PurchasesController::class, 'receive'])->whereNumber('id');

        Route::post('sales/checkout', [SalesController::class, 'checkout']);
        Route::get('sales/{id?}', [SalesController::class, 'index'])->whereNumber('id');
        Route::get('receipts/{id?}', [SalesController::class, 'index'])->whereNumber('id');
        Route::get('payments/{id?}', [PaymentsController::class, 'index'])->whereNumber('id');
        Route::post('payments', [PaymentsController::class, 'save']);
        Route::get('credit-customers', [PaymentsController::class, 'creditCustomers']);
        Route::post('credit-customers/{customer}/repayments', [PaymentsController::class, 'save'])->whereNumber('customer');
        Route::get('analytics', [ReportsController::class, 'index']);
        Route::get('reports', [ReportsController::class, 'index']);
        Route::get('dashboard', [ReportsController::class, 'index']);

        $directories = ['branches', 'customers', 'products', 'suppliers', 'expenses'];
        Route::get('{resource}/{id?}', [DirectoryController::class, 'index'])->whereIn('resource', $directories)->whereNumber('id');
        Route::post('{resource}', [DirectoryController::class, 'save'])->whereIn('resource', $directories);
        Route::patch('{resource}/{id}', [DirectoryController::class, 'save'])->whereIn('resource', $directories)->whereNumber('id');
    });
});
