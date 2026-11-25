<?php

namespace App\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportsController
{
    public function index(Request $request, Workspace $workspace): array
    {
        $workspace->authorize(Workspace::FINANCE);
        $data = $request->validate([
            'from' => 'sometimes|required|date_format:Y-m-d',
            'to' => 'sometimes|required|date_format:Y-m-d',
            'branch_id' => 'sometimes|required|integer',
            'timezone' => 'sometimes|required|timezone',
            'from_at' => 'sometimes|required|date_format:Y-m-d\TH:i:sP',
            'to_at' => 'sometimes|required|date_format:Y-m-d\TH:i:sP',
        ] + SaleFilters::rules($workspace));
        $zone = $data['timezone'] ?? 'Africa/Nairobi';
        $today = CarbonImmutable::today($zone);
        $precise = isset($data['from_at']) || isset($data['to_at']);
        if ($precise && (! isset($data['from_at'], $data['to_at']) || isset($data['from']) || isset($data['to']))) {
            throw ValidationException::withMessages(['from_at' => 'Provide both timestamps or use the date filters.']);
        }
        $start = $precise
            ? CarbonImmutable::parse($data['from_at'])->setTimezone($zone)
            : CarbonImmutable::parse($data['from'] ?? $today->subDays(6)->toDateString(), $zone)->startOfDay();
        $end = $precise
            ? CarbonImmutable::parse($data['to_at'])->setTimezone($zone)
            : CarbonImmutable::parse($data['to'] ?? $today->toDateString(), $zone)->startOfDay()->addDay();
        if ($end <= $start || $end > $start->addDays(366)) {
            throw ValidationException::withMessages([$precise ? 'to_at' : 'to' => 'Choose an ordered range of at most 366 days.']);
        }
        $from = $start->toDateString();
        $to = $end->subSecond()->toDateString();
        $range = [$start->utc(), $end->utc()];
        $branch = isset($data['branch_id']) ? $workspace->branch($data['branch_id']) : null;
        $sales = $workspace->operations('sales');
        $payments = $workspace->operations('payments');
        $expenses = $workspace->operations('expenses')->whereBetween('incurred_on', [$from, $to]);
        $inventory = $workspace->operations('inventory');
        $purchases = $workspace->operations('purchases');
        foreach ([$sales, $payments, $inventory, $expenses, $purchases] as $query) {
            if ($branch !== null) {
                $query->where('branch_id', $branch);
            }
        }
        foreach ([$sales, $payments] as $query) {
            $query->where('created_at', '>=', $range[0])->where('created_at', '<', $range[1]);
        }

        SaleFilters::apply($sales, $data, $workspace);
        $salePayments = (clone $payments)->where('kind', 'sale')->whereIn('sale_id', (clone $sales)->select('id'));
        $repayments = $payments->where('kind', 'repayment');
        if (isset($data['customer_id'])) {
            $repayments->where('customer_id', $data['customer_id']);
        }

        return DB::transaction(function () use ($workspace, $sales, $salePayments, $repayments, $inventory, $expenses, $purchases, $zone, $from, $to, $range): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $totals = (clone $sales)->selectRaw('count(*) AS sale_count, coalesce(sum(total_minor), 0)::bigint AS sales_minor, coalesce(round(avg(total_minor)), 0)::bigint AS average_minor')->first();
            $lines = (clone $sales)->crossJoin(DB::raw('LATERAL jsonb_array_elements(items) AS line'));
            $cost = (clone $lines)
                ->selectRaw("coalesce(sum((line->>'cost_minor')::bigint * (line->>'quantity')::bigint), 0)::bigint AS cost_minor")
                ->value('cost_minor');
            $subtotal = (int) (clone $sales)->sum('subtotal_minor');
            $tax = (int) (clone $sales)->sum('tax_minor');
            $purchaseSummary = $purchases
                ->crossJoin(DB::raw('LATERAL jsonb_array_elements(purchases.items) AS item'))
                ->selectRaw(
                    "count(DISTINCT purchases.id) FILTER (WHERE created_at >= ? AND created_at < ?) AS orders_placed,
                     count(DISTINCT purchases.id) FILTER (WHERE received_at >= ? AND received_at < ?) AS orders_received,
                     coalesce(sum((item->>'cost_minor')::bigint * (item->>'quantity')::bigint)
                         FILTER (WHERE received_at >= ? AND received_at < ?), 0)::bigint AS received_cost_minor",
                    [...$range, ...$range, ...$range],
                )
                ->where(function ($query) use ($range): void {
                    [$start, $end] = $range;
                    $query->where(fn ($query) => $query->where('created_at', '>=', $start)->where('created_at', '<', $end))
                        ->orWhere(fn ($query) => $query->where('received_at', '>=', $start)->where('received_at', '<', $end));
                })->first();
            $low = (clone $inventory)->whereColumn('quantity', '<=', 'reorder_level');

            return [
                'from' => $from,
                'to' => $to,
                'from_at' => $range[0]->toIso8601String(),
                'to_at' => $range[1]->toIso8601String(),
                'timezone' => $zone,
                'currency' => $workspace->settings()->currency,
                'totals' => $totals,
                'purchases' => $purchaseSummary,
                'gross_profit_minor' => $subtotal - $cost,
                'financial_summary' => [
                    'sales_excluding_tax_minor' => $subtotal,
                    'tax_minor' => $tax,
                    'estimated_cost_minor' => $cost,
                    'recorded_expenses_minor' => (int) $expenses->sum('amount_minor'),
                    'credit_sales_minor' => (int) (clone $salePayments)->where('method', 'credit')->sum('amount_minor'),
                    'credit_repayments_minor' => (int) $repayments->sum('amount_minor'),
                ],
                'top_products' => $lines
                    ->selectRaw("(line->>'product_id')::bigint AS product_id, sum((line->>'quantity')::bigint)::bigint AS quantity, sum((line->>'total_minor')::bigint)::bigint AS sales_excluding_tax_minor")
                    ->groupByRaw('1')->orderByDesc('sales_excluding_tax_minor')->orderBy('product_id')->limit(20)->get(),
                'daily_sales' => (clone $sales)->selectRaw('(created_at AT TIME ZONE ?)::date AS day, branch_id, sum(total_minor)::bigint AS total_minor', [$zone])
                    ->groupByRaw('1, branch_id')->orderBy('day')->orderBy('branch_id')->get(),
                'payment_mix' => $salePayments->selectRaw('method, sum(amount_minor)::bigint AS total_minor')->groupBy('method')->orderBy('method')->get(),
                'cashiers' => (clone $sales)->selectRaw('user_id, count(*) AS sale_count, sum(total_minor)::bigint AS total_minor')
                    ->groupBy('user_id')->orderBy('user_id')->get(),
                'low_stock_count' => (clone $low)->count(),
                'low_stock' => $low->orderBy('branch_id')->orderBy('product_id')->limit(50)->get(),
                'stock_categories' => $inventory->join('products', 'inventory.product_id', '=', 'products.id')
                    ->where('products.shop_id', $workspace->shop)
                    ->selectRaw("coalesce(products.category, 'Uncategorized') AS category, sum(inventory.quantity) AS quantity")
                    ->groupBy('category')->orderBy('category')->get(),
            ];
        });
    }
}
