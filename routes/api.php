<?php

use App\Http\AdministrationController;
use App\Http\AuthController;
use App\Http\AuditController;
use App\Http\AuditMutation;
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
        Route::get('audit-events', [AuditController::class, 'index'])->middleware('throttle:30,1,audit:');
        Route::get('me', fn (Request $request) => $request->user());
        Route::post('auth/logout', [AuthController::class, 'logout'])->middleware(AuditMutation::class.':auth.logout');
        Route::get('shops', [AdministrationController::class, 'shops']);
        Route::post('shops', [AdministrationController::class, 'onboard'])->middleware(AuditMutation::class.':shop.create,shop');
        Route::post('shops/{shop}/administrators', [AdministrationController::class, 'addAdministrator'])
            ->whereNumber('shop')->middleware(AuditMutation::class.':shop.administrator.create,user');
        Route::get('settings', [AdministrationController::class, 'settings']);
        Route::patch('settings', [AdministrationController::class, 'updateSettings'])->middleware(AuditMutation::class.':settings.update,shop');
        Route::patch('preferences', [AdministrationController::class, 'preferences'])->middleware(AuditMutation::class.':preferences.update,user');
        Route::get('team/{id?}', [AdministrationController::class, 'team'])->whereNumber('id');
        Route::get('users/{id?}', [AdministrationController::class, 'team'])->whereNumber('id');
        Route::post('users/invitations', [AdministrationController::class, 'invite'])->middleware('throttle:10,1')->middleware(AuditMutation::class.':user.invite,user');
        Route::post('users', [AdministrationController::class, 'user'])->middleware(AuditMutation::class.':user.create,user');
        Route::patch('users/{id}', [AdministrationController::class, 'user'])->whereNumber('id')->middleware(AuditMutation::class.':user.update,user');

        Route::get('{resource}/template', [ImportController::class, 'template'])->whereIn('resource', array_keys(ImportController::COLUMNS));
        Route::post('{resource}/import', [ImportController::class, 'import'])->whereIn('resource', array_keys(ImportController::COLUMNS))->middleware('throttle:5,1,imports:')->middleware(AuditMutation::class.':records.import');

        Route::get('inventory', [InventoryController::class, 'index']);
        Route::get('inventory/movements', [InventoryController::class, 'movements']);
        Route::get('inventory/{branch}/{product}', [InventoryController::class, 'show'])->whereNumber(['branch', 'product']);
        Route::put('inventory/{branch}/{product}', [InventoryController::class, 'configure'])->whereNumber(['branch', 'product'])->middleware(AuditMutation::class.':inventory.configure,product');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])->middleware(AuditMutation::class.':inventory.adjust,product');
        Route::post('inventory/transfers', [InventoryController::class, 'transfer'])->middleware(AuditMutation::class.':inventory.transfer,product');

        Route::get('purchases/{id?}', [PurchasesController::class, 'index'])->whereNumber('id');
        Route::post('purchases', [PurchasesController::class, 'save'])->middleware(AuditMutation::class.':purchase.create,purchase');
        Route::patch('purchases/{id}', [PurchasesController::class, 'save'])->whereNumber('id')->middleware(AuditMutation::class.':purchase.update,purchase');
        Route::post('purchases/{id}/receive', [PurchasesController::class, 'receive'])->whereNumber('id')->middleware(AuditMutation::class.':purchase.receive,purchase');

        Route::post('sales/checkout', [SalesController::class, 'checkout'])->middleware(AuditMutation::class.':sale.checkout,sale');
        Route::get('sales/{id?}', [SalesController::class, 'index'])->whereNumber('id');
        Route::get('receipts/{id?}', [SalesController::class, 'index'])->whereNumber('id');
        Route::get('payments/{id?}', [PaymentsController::class, 'index'])->whereNumber('id');
        Route::post('payments', [PaymentsController::class, 'save'])->middleware(AuditMutation::class.':payment.create,payment');
        Route::get('credit-customers', [PaymentsController::class, 'creditCustomers']);
        Route::post('credit-customers/{customer}/repayments', [PaymentsController::class, 'save'])->whereNumber('customer')->middleware(AuditMutation::class.':credit.repay,payment');
        Route::middleware('throttle:30,1,analytics:')->group(function (): void {
            Route::get('analytics', [ReportsController::class, 'index']);
            Route::get('reports', [ReportsController::class, 'index']);
            Route::get('dashboard', [ReportsController::class, 'index']);
        });

        $directories = ['branches', 'customers', 'products', 'suppliers', 'expenses'];
        Route::get('{resource}/{id?}', [DirectoryController::class, 'index'])->whereIn('resource', $directories)->whereNumber('id');
        Route::post('{resource}', [DirectoryController::class, 'save'])->whereIn('resource', $directories)->middleware(AuditMutation::class.':record.create');
        Route::patch('{resource}/{id}', [DirectoryController::class, 'save'])->whereIn('resource', $directories)->whereNumber('id')->middleware(AuditMutation::class.':record.update');
    });
});
