<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentsController
{
    public function index(Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(Workspace::FINANCE);
        $query = $workspace->operations('payments');

        return $id === null ? $query->orderByDesc('id')->paginate(50) : response()->json($query->where('id', $id)->firstOrFail());
    }

    public function creditCustomers(Workspace $workspace)
    {
        $workspace->authorize(['administrator']);

        return $workspace->records('customers')->where('credit_limit_minor', '>', 0)->orderBy('id')->paginate(50);
    }

    public function save(Request $request, Workspace $workspace, ?int $customer = null)
    {
        $workspace->authorize($customer === null ? Workspace::FINANCE : ['administrator']);
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'idempotency_key' => 'required|uuid',
            'method' => ['required', Rule::in(Workspace::METHODS)],
            'amount_minor' => 'required|integer|between:1,100000000000',
            'reference' => 'nullable|string|max:160',
        ]);
        $branch = $workspace->branch($data['branch_id']);

        return DB::transaction(function () use ($workspace, $customer, $branch, $data) {
            DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->first();
            $existing = $workspace->records('payments')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(
                    $existing->branch_id === $branch && $existing->user_id === $workspace->user->id
                        && $existing->customer_id === $customer && $existing->method === $data['method']
                        && $existing->amount_minor === (int) $data['amount_minor'] && $existing->reference === ($data['reference'] ?? null),
                    409,
                    'The payment key is already in use.',
                );

                return response()->json($existing);
            }

            if ($customer !== null) {
                $query = $workspace->records('customers')->where('id', $customer);
                $account = (clone $query)->lockForUpdate()->firstOrFail();
                if ($data['amount_minor'] > $account->balance_minor) {
                    throw ValidationException::withMessages(['amount_minor' => 'Repayment exceeds the outstanding balance.']);
                }
                $query->decrement('balance_minor', $data['amount_minor']);
            }
            $id = $this->record(
                $workspace, $branch, $customer === null ? 'manual' : 'repayment',
                $data['method'], $data['amount_minor'], $data['reference'] ?? null, $customer, key: $data['idempotency_key'],
            );

            return response()->json($workspace->operations('payments')->where('id', $id)->firstOrFail(), 201);
        }, 3);
    }

    public function record(Workspace $workspace, int $branch, string $kind, string $method, int $amount, ?string $reference, ?int $customer = null, ?int $sale = null, ?string $key = null): int
    {
        return DB::table('payments')->insertGetId([
            'shop_id' => $workspace->shop,
            'branch_id' => $branch,
            'user_id' => $workspace->user->id,
            'sale_id' => $sale,
            'idempotency_key' => $key,
            'customer_id' => $customer,
            'kind' => $kind,
            'method' => $method,
            'amount_minor' => $amount,
            'reference' => $reference,
        ]);
    }
}
