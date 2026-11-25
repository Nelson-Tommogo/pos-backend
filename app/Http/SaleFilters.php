<?php

namespace App\Http;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class SaleFilters
{
    public static function rules(Workspace $workspace): array
    {
        return [
            'salesperson_id' => ['sometimes', 'required', 'integer', $workspace->exists('users')],
            'customer_id' => ['sometimes', 'required', 'integer', $workspace->exists('customers')],
            'product_id' => ['sometimes', 'required', 'integer', $workspace->exists('products')],
            'payment_method' => ['sometimes', 'required', Rule::in([...Workspace::METHODS, 'credit'])],
        ];
    }

    public static function apply(Builder $sales, array $filters, Workspace $workspace): void
    {
        foreach (['salesperson_id' => 'user_id', 'customer_id' => 'customer_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $sales->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['product_id'])) {
            $sales->whereJsonContains('items', [['product_id' => (int) $filters['product_id']]]);
        }
        if (isset($filters['payment_method'])) {
            $sales->whereExists($workspace->operations('payments')->selectRaw('1')
                ->whereColumn('payments.sale_id', 'sales.id')
                ->where('method', $filters['payment_method']));
        }
    }
}
