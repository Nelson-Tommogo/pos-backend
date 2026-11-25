<?php

namespace App\Billing;

use App\Http\AuditTrail;
use App\Http\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubscriptionPayments
{
    public function __construct(private Daraja $daraja) {}

    public function start(Workspace $workspace, int $invoiceId, string $key, string $phone): array
    {
        [$attempt, $amount] = DB::transaction(function () use ($workspace, $invoiceId, $key, $phone) {
            DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->firstOrFail();
            $invoice = $workspace->records('subscription_invoices')->where('id', $invoiceId)->lockForUpdate()->firstOrFail();
            $attempts = $workspace->records('subscription_payment_attempts');
            $prior = (clone $attempts)->where('request_key', $key)->first();
            if ($prior !== null) {
                abort_unless($prior->invoice_id === $invoiceId && $prior->phone === $phone, 409, 'The payment key is already in use.');

                return [$prior, null];
            }

            abort_unless($this->daraja->configured(), 503, 'M-Pesa is unavailable.');
            abort_if($invoice->paid_at !== null || $invoice->cancelled_at !== null, 409, 'The invoice is already closed.');
            abort_if((clone $attempts)->where('invoice_id', $invoiceId)->whereIn('state', ['starting', 'pending', 'review', 'confirmed'])->exists(),
                409, 'This invoice already has a payment attempt awaiting resolution.');
            $id = DB::table('subscription_payment_attempts')->insertGetId([
                'shop_id' => $workspace->shop,
                'invoice_id' => $invoiceId,
                'request_key' => $key,
                'phone' => $phone,
                'state' => 'starting',
            ]);
            AuditTrail::record($workspace->user, 'subscription.stk_start', 'subscription_payment', $id, details: ['invoice_id' => $invoiceId]);

            return [$attempts->where('id', $id)->firstOrFail(), $invoice->amount_minor];
        }, 3);

        if ($amount === null) {
            return [$attempt, false];
        }

        $change = ['state' => 'review'];
        try {
            $body = $this->daraja->push($invoiceId, $amount, $phone);
            $code = $this->code($body['ResponseCode'] ?? null);
            if ($code === '0' && $this->validIdentifier($body['CheckoutRequestID'] ?? null) && $this->validIdentifier($body['MerchantRequestID'] ?? null)) {
                $change = [
                    'state' => 'pending',
                    'checkout_request_id' => $body['CheckoutRequestID'],
                    'merchant_request_id' => $body['MerchantRequestID'],
                ];
            } elseif ($code !== null && $code !== '0') {
                $change = ['state' => 'failed', 'result_code' => $code];
            }
        } catch (DarajaNotSubmitted) {
            $change = ['state' => 'failed'];
        } catch (Throwable) {
            // A transport error after submission can leave a live customer prompt.
            logger()->warning('Subscription STK submission needs review.', ['attempt_id' => $attempt->id]);
        }
        $query = $workspace->records('subscription_payment_attempts')->where('id', $attempt->id);
        (clone $query)->where('state', 'starting')->update($change);

        return [$query->firstOrFail(), true];
    }

    public function reconcile(object $attempt): object
    {
        if ($attempt->state !== 'pending') {
            return $attempt;
        }
        abort_unless($this->daraja->configured(), 503, 'M-Pesa is unavailable.');
        $claim = DB::table('subscription_payment_attempts')->where('id', $attempt->id)->where('state', 'pending')
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', now()->subSeconds(30)))
            ->update(['checked_at' => now()]);
        if ($claim === 0) {
            return DB::table('subscription_payment_attempts')->where('id', $attempt->id)->firstOrFail();
        }

        try {
            $body = $this->daraja->query($attempt->checkout_request_id);
        } catch (Throwable) {
            logger()->warning('Subscription payment query could not be completed.', ['attempt_id' => $attempt->id]);

            return $this->attempt($attempt->id);
        }
        if (($body['CheckoutRequestID'] ?? null) !== $attempt->checkout_request_id
            || ($body['MerchantRequestID'] ?? null) !== $attempt->merchant_request_id
            || $this->code($body['ResponseCode'] ?? null) !== '0') {
            return $this->attempt($attempt->id);
        }
        $result = $this->code($body['ResultCode'] ?? null);
        if ($result === '0') {
            $this->settle($attempt);
        } elseif (in_array($result, ['1', '1032'], true)) {
            DB::table('subscription_payment_attempts')->where('id', $attempt->id)->where('state', 'pending')
                ->update(['state' => 'failed', 'result_code' => $result]);
        }

        return $this->attempt($attempt->id);
    }

    private function settle(object $attempt): void
    {
        DB::transaction(function () use ($attempt): void {
            DB::table('shops')->where('id', $attempt->shop_id)->lockForUpdate()->firstOrFail();
            $attempt = DB::table('subscription_payment_attempts')->where('id', $attempt->id)->lockForUpdate()->firstOrFail();
            if ($attempt->state !== 'pending') {
                return;
            }
            $invoiceQuery = DB::table('subscription_invoices')->where('id', $attempt->invoice_id);
            $invoice = (clone $invoiceQuery)->lockForUpdate()->firstOrFail();
            if ($invoice->paid_at !== null || $invoice->cancelled_at !== null) {
                return;
            }
            $current = DB::table('shop_subscriptions')->where('shop_id', $attempt->shop_id)->first();
            $now = CarbonImmutable::now();
            $expiry = $current ? CarbonImmutable::parse($current->covered_until)->utc() : $now;
            $start = $expiry->isFuture() ? $expiry : $now;

            DB::table('shop_subscriptions')->upsert([[
                'shop_id' => $attempt->shop_id,
                'plan_id' => $invoice->plan_id,
                'covered_from' => $start === $expiry && $current !== null ? CarbonImmutable::parse($current->covered_from)->utc() : $now,
                'covered_until' => $start->addMonthsNoOverflow($invoice->interval_months),
                'last_invoice_id' => $invoice->id,
            ]], ['shop_id'], ['plan_id', 'covered_from', 'covered_until', 'last_invoice_id']);
            $invoiceQuery->update(['paid_at' => $now]);
            DB::table('subscription_payment_attempts')->where('id', $attempt->id)
                ->update(['state' => 'confirmed', 'result_code' => '0', 'confirmed_at' => $now]);
        }, 3);
    }

    private function attempt(int $id): object
    {
        return DB::table('subscription_payment_attempts')->where('id', $id)->firstOrFail();
    }

    private function validIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $value) === 1;
    }

    private function code(mixed $value): ?string
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[0-9]{1,12}$/D', (string) $value) ? (string) $value : null;
    }
}
