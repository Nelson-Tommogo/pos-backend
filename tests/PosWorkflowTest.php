<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class PosWorkflowTest extends PostgresCase
{
    public function testReceivingCheckoutCreditAnalyticsAndMail(): void
    {
        [$admin] = $this->shop($this->platform());
        $branch = $this->branch($admin);
        [$cashier, $cashierId] = $this->cashier($admin, $branch);
        $product = $this->api(201, 'POST', 'products', [
            'name' => 'Tea', 'sku' => 'T01', 'price_minor' => 10000, 'cost_minor' => 3500,
        ], $admin)['id'];
        $supplier = $this->api(201, 'POST', 'suppliers', ['name' => 'Supplier'], $admin)['id'];
        $purchase = $this->api(201, 'POST', 'purchases', [
            'branch_id' => $branch, 'supplier_id' => $supplier,
            'items' => [['product_id' => $product, 'quantity' => 5, 'cost_minor' => 3500]],
        ], $admin)['id'];
        $this->api(200, 'POST', "purchases/$purchase/receive", token: $admin);
        $this->api(200, 'POST', "purchases/$purchase/receive", token: $admin);
        $customer = $this->api(201, 'POST', 'customers', [
            'name' => 'Buyer', 'email' => 'buyer@example.test', 'credit_limit_minor' => 15000,
        ], $admin)['id'];
        $this->api(200, 'PATCH', 'settings', ['tax_bps' => 1600], $admin);
        $checkout = [
            'idempotency_key' => $this->key(), 'branch_id' => $branch, 'customer_id' => $customer,
            'items' => [['product_id' => $product, 'quantity' => 1]], 'method' => 'credit', 'email_receipt' => true,
        ];
        $sale = $this->api(201, 'POST', 'sales/checkout', $checkout, $cashier);
        $this->assertSame(11600, $sale['total_minor']);
        $this->assertArrayNotHasKey('cost_minor', $sale['items'][0]);
        $this->assertSame($sale['id'], $this->api(200, 'POST', 'sales/checkout', $checkout, $cashier)['id']);
        $this->api(409, 'POST', 'sales/checkout', array_replace($checkout, ['reference' => 'changed']), $cashier);
        $this->api(422, 'POST', 'sales/checkout', array_replace($checkout, ['idempotency_key' => $this->key()]), $cashier);
        $this->api(422, 'POST', 'sales/checkout', array_replace($checkout, [
            'idempotency_key' => $this->key(), 'method' => 'cash',
            'items' => [['product_id' => $product, 'quantity' => 50]],
        ]), $cashier);
        $this->assertSame(4, $this->api(200, 'GET', "inventory/$branch/$product", token: $admin)['quantity']);

        $repayment = ['idempotency_key' => $this->key(), 'branch_id' => $branch, 'method' => 'cash', 'amount_minor' => 11600];
        $payment = $this->api(201, 'POST', "credit-customers/$customer/repayments", $repayment, $admin);
        $this->assertSame($payment['id'], $this->api(200, 'POST', "credit-customers/$customer/repayments", $repayment, $admin)['id']);

        $filters = http_build_query(['branch_id' => $branch, 'customer_id' => $customer, 'product_id' => $product,
            'salesperson_id' => $cashierId, 'payment_method' => 'credit']);
        $analytics = $this->api(200, 'GET', 'analytics?'.$filters, token: $admin);
        $this->assertSame(6500, $analytics['gross_profit_minor']);
        $this->assertSame(17500, $analytics['purchases']['received_cost_minor']);
        $this->assertSame(11600, $analytics['financial_summary']['credit_repayments_minor']);
        $this->assertSame(1, $analytics['totals']['sale_count']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sale.checkout')->count());
        $this->assertSame(4, DB::table('jobs')->where('queue', 'mail')->count());

        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'mail', '--stop-when-empty' => true, '--sleep' => 0,
        ]));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(4, $messages);
        $receipt = $messages->filter(fn ($message) => $message->getOriginalMessage()->getSubject() === 'Receipt #'.$sale['id']);
        $this->assertCount(1, $receipt);
        $this->assertSame('buyer@example.test', $receipt->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }
}
