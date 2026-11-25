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
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d',
            'branch_id' => 'sometimes|integer',
            'timezone' => 'sometimes|timezone',
        ]);
        $zone = $data['timezone'] ?? 'Africa/Nairobi';
        $today = CarbonImmutable::today($zone);
        $from = CarbonImmutable::parse($data['from'] ?? $today->subDays(6)->toDateString(), $zone)->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? $today->toDateString(), $zone)->startOfDay();
        if ($from > $to || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose an ordered date range of at most 366 days.']);
        }
        $branch = isset($data['branch_id']) ? $workspace->branch($data['branch_id']) : null;
        $sales = $workspace->operations('sales');
        $payments = $workspace->operations('payments');
        $expenses = $workspace->operations('expenses')->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()]);
        $inventory = $workspace->operations('inventory');
        foreach ([$sales, $payments, $inventory, $expenses] as $query) {
            if ($branch !== null) {
                $query->where('branch_id', $branch);
            }
        }
        foreach ([$sales, $payments] as $query) {
            $query->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->addDay()->utc());
        }

        return DB::transaction(function () use ($workspace, $sales, $payments, $inventory, $expenses, $zone, $from, $to): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $totals = (clone $sales)->selectRaw('count(*) AS sale_count, coalesce(sum(total_minor), 0)::bigint AS sales_minor, coalesce(round(avg(total_minor)), 0)::bigint AS average_minor')->first();
            $lines = (clone $sales)->crossJoin(DB::raw('LATERAL jsonb_array_elements(items) AS line'));
            $cost = (clone $lines)
                ->selectRaw("coalesce(sum((line->>'cost_minor')::bigint * (line->>'quantity')::bigint), 0)::bigint AS cost_minor")
                ->value('cost_minor');
            $subtotal = (int) (clone $sales)->sum('subtotal_minor');
            $tax = (int) (clone $sales)->sum('tax_minor');
            $low = (clone $inventory)->whereColumn('quantity', '<=', 'reorder_level');

            return [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'timezone' => $zone,
                'currency' => $workspace->settings()->currency,
                'totals' => $totals,
                'gross_profit_minor' => $subtotal - $cost,
                'financial_summary' => [
                    'sales_excluding_tax_minor' => $subtotal,
                    'tax_minor' => $tax,
                    'estimated_cost_minor' => $cost,
                    'recorded_expenses_minor' => (int) $expenses->sum('amount_minor'),
                    'credit_sales_minor' => (int) (clone $payments)->where('kind', 'sale')->where('method', 'credit')->sum('amount_minor'),
                    'credit_repayments_minor' => (int) (clone $payments)->where('kind', 'repayment')->sum('amount_minor'),
                ],
                'top_products' => $lines
                    ->selectRaw("(line->>'product_id')::bigint AS product_id, sum((line->>'quantity')::bigint)::bigint AS quantity, sum((line->>'total_minor')::bigint)::bigint AS sales_excluding_tax_minor")
                    ->groupByRaw('1')->orderByDesc('sales_excluding_tax_minor')->orderBy('product_id')->limit(20)->get(),
                'daily_sales' => (clone $sales)->selectRaw('(created_at AT TIME ZONE ?)::date AS day, branch_id, sum(total_minor)::bigint AS total_minor', [$zone])
                    ->groupByRaw('1, branch_id')->orderBy('day')->orderBy('branch_id')->get(),
                'payment_mix' => (clone $payments)->where('kind', 'sale')->selectRaw('method, sum(amount_minor)::bigint AS total_minor')->groupBy('method')->orderBy('method')->get(),
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
