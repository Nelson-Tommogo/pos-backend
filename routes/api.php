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
use App\Http\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

foreach (['id', 'shop', 'customer', 'invoice', 'attempt', 'branch', 'product'] as $parameter) {
    Route::pattern($parameter, '[0-9]{1,18}');
}

Route::prefix('v1')->group(function (): void {
    Route::post('billing/mpesa/callback', [SubscriptionController::class, 'callback'])
        ->middleware('throttle:120,1,mpesa-callback:');

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
            ->middleware(AuditMutation::class.':shop.administrator.create,user');
        Route::get('settings', [AdministrationController::class, 'settings']);
        Route::patch('settings', [AdministrationController::class, 'updateSettings'])->middleware(AuditMutation::class.':settings.update,shop');
        Route::patch('preferences', [AdministrationController::class, 'preferences'])->middleware(AuditMutation::class.':preferences.update,user');
        Route::get('team/{id?}', [AdministrationController::class, 'team']);
        Route::get('users/{id?}', [AdministrationController::class, 'team']);
        Route::post('users/invitations', [AdministrationController::class, 'invite'])->middleware('throttle:10,1')->middleware(AuditMutation::class.':user.invite,user');
        Route::post('users', [AdministrationController::class, 'user'])->middleware(AuditMutation::class.':user.create,user');
        Route::patch('users/{id}', [AdministrationController::class, 'user'])->middleware(AuditMutation::class.':user.update,user');

        Route::get('{resource}/template', [ImportController::class, 'template'])->whereIn('resource', array_keys(ImportController::COLUMNS));
        Route::post('{resource}/import', [ImportController::class, 'import'])->whereIn('resource', array_keys(ImportController::COLUMNS))->middleware('throttle:5,1,imports:')->middleware(AuditMutation::class.':records.import');

        Route::get('inventory', [InventoryController::class, 'index']);
        Route::get('inventory/movements', [InventoryController::class, 'movements']);
        Route::get('inventory/{branch}/{product}', [InventoryController::class, 'show']);
        Route::put('inventory/{branch}/{product}', [InventoryController::class, 'configure'])->middleware(AuditMutation::class.':inventory.configure,product');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjust'])->middleware(AuditMutation::class.':inventory.adjust,product');
        Route::post('inventory/transfers', [InventoryController::class, 'transfer'])->middleware(AuditMutation::class.':inventory.transfer,product');

        Route::get('purchases/{id?}', [PurchasesController::class, 'index']);
        Route::post('purchases', [PurchasesController::class, 'save'])->middleware(AuditMutation::class.':purchase.create,purchase');
        Route::patch('purchases/{id}', [PurchasesController::class, 'save'])->middleware(AuditMutation::class.':purchase.update,purchase');
        Route::post('purchases/{id}/receive', [PurchasesController::class, 'receive'])->middleware(AuditMutation::class.':purchase.receive,purchase');

        Route::post('sales/checkout', [SalesController::class, 'checkout'])->middleware(AuditMutation::class.':sale.checkout,sale');
        Route::get('sales/{id?}', [SalesController::class, 'index']);
        Route::get('receipts/{id?}', [SalesController::class, 'index']);
        Route::get('payments/{id?}', [PaymentsController::class, 'index']);
        Route::post('payments', [PaymentsController::class, 'save'])->middleware(AuditMutation::class.':payment.create,payment');
        Route::get('credit-customers', [PaymentsController::class, 'creditCustomers']);
        Route::post('credit-customers/{customer}/repayments', [PaymentsController::class, 'save'])->middleware(AuditMutation::class.':credit.repay,payment');
        Route::middleware('throttle:30,1,analytics:')->group(function (): void {
            Route::get('analytics', [ReportsController::class, 'index']);
            Route::get('reports', [ReportsController::class, 'index']);
            Route::get('dashboard', [ReportsController::class, 'index']);
        });

        Route::prefix('billing')->group(function (): void {
            Route::get('plans', [SubscriptionController::class, 'plans']);
            Route::post('plans', [SubscriptionController::class, 'savePlan'])
                ->middleware(AuditMutation::class.':subscription.plan.create,subscription_plan');
            Route::patch('plans/{id}', [SubscriptionController::class, 'savePlan'])
                ->middleware(AuditMutation::class.':subscription.plan.update,subscription_plan');
            Route::get('subscription', [SubscriptionController::class, 'status']);
            Route::get('invoices/{id?}', [SubscriptionController::class, 'invoices']);
            Route::post('invoices', [SubscriptionController::class, 'invoice'])
                ->middleware(AuditMutation::class.':subscription.invoice.create,subscription_invoice');
            Route::post('invoices/{id}/cancel', [SubscriptionController::class, 'cancel'])
                ->middleware(AuditMutation::class.':subscription.invoice.cancel,subscription_invoice');
            Route::post('invoices/{invoice}/mpesa', [SubscriptionController::class, 'start'])
                ->middleware('throttle:5,1,mpesa-start:');
            Route::post('payments/{attempt}/reconcile', [SubscriptionController::class, 'reconcile'])
                ->middleware('throttle:15,1,mpesa-reconcile:');
            Route::get('reconciliation', [SubscriptionController::class, 'reconciliation']);
            Route::post('reconciliation/{attempt}/close-review', [SubscriptionController::class, 'closeReview']);
        });

        $directories = ['branches', 'customers', 'products', 'suppliers', 'expenses'];
        Route::get('{resource}/{id?}', [DirectoryController::class, 'index'])->whereIn('resource', $directories);
        Route::post('{resource}', [DirectoryController::class, 'save'])->whereIn('resource', $directories)->middleware(AuditMutation::class.':record.create');
        Route::patch('{resource}/{id}', [DirectoryController::class, 'save'])->whereIn('resource', $directories)->middleware(AuditMutation::class.':record.update');
    });
});
