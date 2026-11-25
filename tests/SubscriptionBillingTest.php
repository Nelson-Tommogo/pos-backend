<?php

namespace Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class SubscriptionBillingTest extends PostgresCase
{
    private int $pushes = 0;
    private int $attemptNumber = 0;
    private bool $mismatch = false;
    private bool $oauthAvailable = true;
    private bool $ambiguous = false;
    private array $amounts = [];

    protected function fakeProvider(): void
    {
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/*' => fn () => $this->oauthAvailable
                ? Http::response(['access_token' => 'fake-token']) : Http::response([], 503),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => function (Request $request) {
                $this->pushes++;
                $this->amounts[] = $request['Amount'];
                $this->assertSame('254712345678', $request['PhoneNumber']);

                return $this->ambiguous ? Http::response([], 503) : Http::response($this->providerReply());
            },
            'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => fn () => Http::response($this->providerReply() + ['ResultCode' => '0']),
        ]);
    }

    private function providerReply(): array
    {
        return [
            'ResponseCode' => '0',
            'CheckoutRequestID' => $this->mismatch ? 'unrelated-request' : 'checkout-'.$this->attemptNumber,
            'MerchantRequestID' => 'merchant-'.$this->attemptNumber,
        ];
    }

    private function invoice(string $platform, string $admin): array
    {
        $plan = $this->api(201, 'POST', 'billing/plans', [
            'name' => 'Quarterly', 'interval_months' => 3, 'amount_minor' => 30000,
        ], $platform);
        $body = ['plan_id' => $plan['id'], 'idempotency_key' => $this->key()];
        $invoice = $this->api(201, 'POST', 'billing/invoices', $body, $admin);
        $this->assertSame($invoice['id'], $this->api(200, 'POST', 'billing/invoices', $body, $admin)['id']);

        return $invoice;
    }

    private function start(int $invoice, string $admin, ?string $key = null, int $status = 202): array
    {
        return $this->api($status, 'POST', "billing/invoices/$invoice/mpesa", [
            'idempotency_key' => $key ?? $this->key(), 'phone' => '0712345678',
        ], $admin);
    }

    public function testActivationRequiresMatchingProviderQueryAndExtendsOnlyOnce(): void
    {
        $platform = $this->platform();
        [$admin] = $this->shop($platform);
        [$other] = $this->shop($platform, 'other');
        $invoice = $this->invoice($platform, $admin);
        $id = $invoice['id'];
        $this->api(403, 'PATCH', 'billing/plans/'.$invoice['plan_id'], ['amount_minor' => 100], $admin);
        $this->api(404, 'GET', "billing/invoices/$id", token: $other);
        $this->start($id, $other, status: 404);
        $this->api(200, 'PATCH', 'billing/plans/'.$invoice['plan_id'], ['amount_minor' => 35000, 'interval_months' => 6], $platform);

        $key = $this->key();
        $attempt = $this->start($id, $admin, $key);
        $this->assertSame('pending', $attempt['state']);
        $this->assertArrayNotHasKey('phone', $attempt);
        $this->assertSame($attempt['id'], $this->start($id, $admin, $key, 200)['id']);
        $this->start($id, $admin, status: 409);
        $this->assertSame(1, $this->pushes);
        $this->assertSame([300], $this->amounts);
        $this->api(200, 'POST', 'billing/mpesa/callback', ['Body' => ['stkCallback' => [
            'ResultCode' => 0, 'CheckoutRequestID' => $attempt['checkout_request_id'],
        ]]]);
        $this->assertSame('inactive', $this->api(200, 'GET', 'billing/subscription', token: $admin)['status']);

        $path = 'billing/payments/'.$attempt['id'].'/reconcile';
        $this->api(404, 'POST', $path, token: $other);
        $this->mismatch = true;
        $this->assertSame('pending', $this->api(200, 'POST', $path, token: $admin)['state']);
        DB::table('subscription_payment_attempts')->where('id', $attempt['id'])->update(['checked_at' => now()->subMinute()]);
        $this->mismatch = false;
        $this->assertSame('confirmed', $this->api(200, 'POST', $path, token: $admin)['state']);
        $coverage = $this->api(200, 'GET', 'billing/subscription', token: $admin);
        $this->assertSame('active', $coverage['status']);
        $this->api(200, 'POST', $path, token: $admin);
        $this->assertSame($coverage, $this->api(200, 'GET', 'billing/subscription', token: $admin));

        $renewal = $this->api(201, 'POST', 'billing/invoices', [
            'plan_id' => $invoice['plan_id'], 'idempotency_key' => $this->key(),
        ], $admin);
        $this->attemptNumber++;
        $this->start($renewal['id'], $admin);
        $this->assertSame(0, Artisan::call('pos:reconcile-subscriptions'), Artisan::output());
        $renewed = $this->api(200, 'GET', 'billing/subscription', token: $admin)['subscription'];
        $expected = \Carbon\CarbonImmutable::parse($coverage['subscription']['covered_until'])->setTimezone('Africa/Nairobi')->addMonthsNoOverflow(6);
        $this->assertTrue($expected->equalTo($renewed['covered_until']));
        $this->assertSame([300, 350], $this->amounts);
    }

    public function testProviderAuthenticationFailureAllowsRetryAndUncertainSubmissionRequiresReview(): void
    {
        $platform = $this->platform();
        [$admin] = $this->shop($platform);
        $invoice = $this->invoice($platform, $admin)['id'];

        $this->oauthAvailable = false;
        $this->assertSame('failed', $this->start($invoice, $admin)['state']);
        $this->assertSame(0, $this->pushes);
        $this->oauthAvailable = true;
        $this->ambiguous = true;
        $attempt = $this->start($invoice, $admin);
        $this->assertSame('review', $attempt['state']);
        $this->start($invoice, $admin, status: 409);
        $this->api(409, 'POST', "billing/invoices/$invoice/cancel", token: $admin);

        $close = 'billing/reconciliation/'.$attempt['id'].'/close-review';
        $evidence = ['confirmed_no_debit' => true, 'provider_reference' => 'CASE-123'];
        $this->api(403, 'POST', $close, $evidence, $admin);
        $this->api(422, 'POST', $close, ['provider_reference' => 'CASE-123'], $platform);
        $this->assertSame('failed', $this->api(200, 'POST', $close, $evidence, $platform)['state']);
        $this->api(200, 'POST', "billing/invoices/$invoice/cancel", token: $admin);
        $this->assertSame('inactive', $this->api(200, 'GET', 'billing/subscription', token: $admin)['status']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'subscription.review.close')->count());
    }
}
