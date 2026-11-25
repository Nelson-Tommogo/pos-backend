<?php

namespace App\Http;

use App\Billing\SubscriptionPayments;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController
{
    public function plans(Request $request)
    {
        $query = DB::table('subscription_plans');
        if ($request->user()->role !== 'platform_admin') {
            $query->where('active', true);
        }

        return $query->orderBy('interval_months')->orderBy('id')->paginate(50);
    }

    public function savePlan(Request $request, ?int $id = null)
    {
        abort_unless($request->user()->role === 'platform_admin', 403);
        $required = $id === null ? 'required' : 'sometimes|required';
        $data = $request->validate([
            'name' => "$required|string|max:120",
            'interval_months' => "$required|integer|between:1,36",
            'amount_minor' => "$required|integer|between:100,100000000|multiple_of:100",
            'active' => 'sometimes|required|boolean',
        ]);
        $query = DB::table('subscription_plans');
        if ($id === null) {
            $id = $query->insertGetId($data);

            return response()->json($query->where('id', $id)->firstOrFail(), 201);
        }
        $query->where('id', $id);
        (clone $query)->firstOrFail();
        if ($data !== []) {
            $query->update($data);
        }

        return response()->json($query->firstOrFail());
    }

    public function status(Workspace $workspace): array
    {
        $workspace->authorize(['administrator']);
        $subscription = $workspace->records('shop_subscriptions')->first();

        return [
            'status' => $subscription === null ? 'inactive' : (CarbonImmutable::parse($subscription->covered_until)->isFuture() ? 'active' : 'expired'),
            'subscription' => $subscription,
        ];
    }

    public function invoices(Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(['administrator']);
        $query = $workspace->records('subscription_invoices');
        if ($id === null) {
            return $query->orderByDesc('id')->paginate(50);
        }

        $invoice = $query->where('id', $id)->firstOrFail();
        $invoice->attempts = $workspace->records('subscription_payment_attempts')->where('invoice_id', $id)
            ->select('id', 'invoice_id', 'state', 'checkout_request_id', 'result_code', 'created_at', 'checked_at', 'confirmed_at')
            ->orderByDesc('id')->limit(50)->get();

        return response()->json($invoice);
    }

    public function invoice(Request $request, Workspace $workspace)
    {
        $workspace->authorize(['administrator']);
        $data = $request->validate(['plan_id' => 'required|integer|min:1', 'idempotency_key' => 'required|uuid']);
        DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->firstOrFail();
        $query = $workspace->records('subscription_invoices');
        $existing = (clone $query)->where('request_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            abort_unless($existing->plan_id === (int) $data['plan_id'], 409, 'The invoice key is already in use.');

            return response()->json($existing);
        }
        abort_if((clone $query)->whereNull('paid_at')->whereNull('cancelled_at')->exists(), 409, 'This shop already has an open invoice.');
        $plan = DB::table('subscription_plans')->where('id', $data['plan_id'])->where('active', true)->firstOrFail();
        $id = DB::table('subscription_invoices')->insertGetId([
            'shop_id' => $workspace->shop,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'interval_months' => $plan->interval_months,
            'amount_minor' => $plan->amount_minor,
            'request_key' => $data['idempotency_key'],
        ]);

        return response()->json($query->where('id', $id)->firstOrFail(), 201);
    }

    public function cancel(Workspace $workspace, int $id)
    {
        $workspace->authorize(['administrator']);
        DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->firstOrFail();
        $query = $workspace->records('subscription_invoices')->where('id', $id);
        $invoice = (clone $query)->lockForUpdate()->firstOrFail();
        abort_if($invoice->paid_at !== null || $invoice->cancelled_at !== null, 409, 'This invoice is already closed.');
        abort_if($workspace->records('subscription_payment_attempts')->where('invoice_id', $id)->where('state', '<>', 'failed')->exists(),
            409, 'Resolve the payment attempt before cancelling this invoice.');
        $query->update(['cancelled_at' => now()]);

        return response()->json($query->firstOrFail());
    }

    public function start(Request $request, Workspace $workspace, SubscriptionPayments $payments, int $invoice)
    {
        $workspace->authorize(['administrator']);
        $data = $request->validate([
            'idempotency_key' => 'required|uuid',
            'phone' => ['required', 'string', 'regex:/\A(?:\+?254|0)[17][0-9]{8}\z/'],
        ]);
        $phone = str_starts_with($data['phone'], '0') ? '254'.substr($data['phone'], 1) : ltrim($data['phone'], '+');
        [$attempt, $created] = $payments->start($workspace, $invoice, $data['idempotency_key'], $phone);

        return response()->json($this->publicAttempt($attempt), $created ? 202 : 200);
    }

    public function reconcile(Workspace $workspace, SubscriptionPayments $payments, int $attempt)
    {
        $workspace->authorize(['administrator']);
        $record = $workspace->records('subscription_payment_attempts')->where('id', $attempt)->firstOrFail();

        return response()->json($this->publicAttempt($payments->reconcile($record)));
    }

    public function reconciliation(Request $request)
    {
        abort_unless($request->user()->role === 'platform_admin', 403);
        $data = $request->validate([
            'shop_id' => 'sometimes|required|integer|min:1',
            'state' => ['sometimes', 'required', Rule::in(['starting', 'pending', 'review', 'failed', 'confirmed'])],
        ]);
        $query = DB::table('subscription_payment_attempts');
        foreach ($data as $column => $value) {
            $query->where($column, $value);
        }

        return $query->orderByDesc('id')->paginate(50)->withQueryString()->through($this->publicAttempt(...));
    }

    public function closeReview(Request $request, int $attempt)
    {
        $actor = $request->user();
        abort_unless($actor->role === 'platform_admin', 403);
        $data = $request->validate([
            'provider_reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{3,60}\z/'],
            'confirmed_no_debit' => 'required|accepted',
        ]);
        return DB::transaction(function () use ($attempt, $actor, $data) {
            $query = DB::table('subscription_payment_attempts')->where('id', $attempt);
            $record = (clone $query)->lockForUpdate()->firstOrFail();
            abort_unless($record->state === 'review' && $record->checkout_request_id === null, 409, 'This attempt is not eligible for manual closure.');
            $query->update(['state' => 'failed', 'checked_at' => now()]);
            AuditTrail::record($actor, 'subscription.review.close', 'subscription_payment', $attempt,
                details: ['provider_reference' => $data['provider_reference']], shopId: $record->shop_id);

            return response()->json($this->publicAttempt($query->firstOrFail()));
        });
    }

    public function callback()
    {
        // Callback contents are not a source of payment truth. The worker queries Daraja directly.
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    private function publicAttempt(object $attempt): object
    {
        unset($attempt->phone, $attempt->request_key, $attempt->merchant_request_id);

        return $attempt;
    }
}
