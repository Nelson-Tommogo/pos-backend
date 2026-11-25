<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesController
{
    public function index(Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(array_unique([...Workspace::SALES, ...Workspace::FINANCE]));
        $query = $workspace->operations('sales');
        if ($id !== null) {
            return response()->json($this->receipt($workspace, $query->where('id', $id)->firstOrFail()));
        }

        return $query->select('id', 'branch_id', 'user_id', 'customer_id', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'created_at')
            ->orderByDesc('id')->paginate(50);
    }

    public function checkout(Request $request, Workspace $workspace, InventoryController $inventory, PaymentsController $payments)
    {
        $workspace->authorize(Workspace::SALES);
        $data = $request->validate([
            'idempotency_key' => 'required|uuid',
            'branch_id' => 'required|integer',
            'customer_id' => ['nullable', 'integer', $workspace->exists('customers'), 'required_if:method,credit'],
            'customer' => 'nullable|array:name,phone,email',
            'customer.name' => 'nullable|string|max:160',
            'customer.phone' => 'nullable|string|max:60',
            'customer.email' => 'nullable|email|max:255',
            'items' => 'required|array|list|min:1|max:100',
            'items.*' => 'required|array:product_id,quantity',
            'items.*.product_id' => ['required', 'integer', 'distinct', $workspace->exists('products')],
            'items.*.quantity' => 'required|integer|between:1,10000',
            'method' => ['required', Rule::in([...Workspace::METHODS, 'credit'])],
            'reference' => 'nullable|string|max:160',
        ]);
        $branch = $workspace->branch($data['branch_id']);
        usort($data['items'], fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $data['items'] = array_map(fn ($item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
        ], $data['items']);
        $customerDetails = $data['customer'] ?? [];
        ksort($customerDetails);
        $hash = hash('sha256', json_encode([
            $branch, (int) ($data['customer_id'] ?? 0), $customerDetails,
            $data['items'], $data['method'], $data['reference'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($workspace, $inventory, $payments, $data, $branch, $hash) {
            $shop = DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->first();
            $existing = $workspace->records('sales')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless($existing->user_id === $workspace->user->id && hash_equals($existing->request_hash, $hash), 409, 'The checkout key is already in use.');

                return response()->json($this->receipt($workspace, $existing));
            }

            $products = $workspace->records('products')->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
            $items = [];
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                $lineTotal = $product->price_minor * $item['quantity'];
                $subtotal += $lineTotal;
                $items[] = $item + [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category,
                    'price_minor' => $product->price_minor,
                    'cost_minor' => $product->cost_minor,
                    'total_minor' => $lineTotal,
                ];
            }
            $tax = intdiv($subtotal * $shop->tax_bps + 5000, 10000);
            $total = $subtotal + $tax;
            $customer = null;
            if (isset($data['customer_id'])) {
                $query = $workspace->records('customers')->where('id', $data['customer_id']);
                $customer = $query->lockForUpdate()->firstOrFail();
                if ($data['method'] === 'credit') {
                    if ($customer->credit_limit_minor === 0 || $customer->balance_minor + $total > $customer->credit_limit_minor) {
                        throw ValidationException::withMessages(['customer_id' => 'The sale exceeds the available credit.']);
                    }
                    $query->increment('balance_minor', $total);
                }
            }
            $details = array_replace(
                $customer ? ['name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email] : [],
                $data['customer'] ?? [],
            );

            $sale = DB::table('sales')->insertGetId([
                'shop_id' => $workspace->shop,
                'branch_id' => $branch,
                'user_id' => $workspace->user->id,
                'customer_id' => $customer?->id,
                'customer' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'idempotency_key' => $data['idempotency_key'],
                'request_hash' => $hash,
                'currency' => $shop->currency,
                'tax_bps' => $shop->tax_bps,
                'branding' => json_encode((array) json_decode($shop->receipt) + ['name' => $shop->name], JSON_THROW_ON_ERROR),
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'items' => json_encode($items, JSON_THROW_ON_ERROR),
            ]);
            foreach ($items as $item) {
                $inventory->move($workspace, $branch, $item['product_id'], -$item['quantity'], 'sale', $sale);
            }
            $payments->record($workspace, $branch, 'sale', $data['method'], $total, $data['reference'] ?? null, $customer?->id, $sale);

            return response()->json($this->receipt($workspace, $workspace->operations('sales')->where('id', $sale)->firstOrFail()), 201);
        }, 3);
    }

    private function receipt(Workspace $workspace, object $sale): object
    {
        unset($sale->request_hash, $sale->idempotency_key);
        foreach (['items', 'customer', 'branding'] as $column) {
            $sale->$column = $sale->$column === null ? null : json_decode($sale->$column);
        }
        if (! in_array($workspace->user->role, Workspace::FINANCE, true)) {
            foreach ($sale->items as $item) {
                unset($item->cost_minor);
            }
        }
        $sale->payments = $workspace->operations('payments')->where('sale_id', $sale->id)->orderBy('id')->get();

        return $sale;
    }
}
