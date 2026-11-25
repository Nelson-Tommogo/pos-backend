<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DirectoryController
{
    private const WRITE_ROLES = [
        'branches' => ['administrator'],
        'products' => Workspace::STOCK,
        'suppliers' => Workspace::STOCK,
        'customers' => Workspace::SALES,
        'expenses' => Workspace::FINANCE,
    ];

    public function index(Request $request, Workspace $workspace, string $resource, ?int $id = null): \Illuminate\Pagination\LengthAwarePaginator|\Illuminate\Http\JsonResponse
    {
        $query = $this->query($workspace, $resource);
        if ($id !== null) {
            return response()->json($query->where('id', $id)->firstOrFail());
        }

        $data = $request->validate(['q' => 'sometimes|string|max:120', 'barcode' => 'sometimes|string|max:120']);
        if ($resource === 'products' && isset($data['barcode'])) {
            $query->where('barcode', $data['barcode']);
        }
        if (isset($data['q'])) {
            $column = $resource === 'expenses' ? 'description' : 'name';
            $query->where(function ($query) use ($resource, $column, $data): void {
                $query->where($column, 'ilike', '%'.$data['q'].'%');
                if ($resource === 'products') {
                    $query->orWhere('sku', 'ilike', '%'.$data['q'].'%')
                        ->orWhere('barcode', $data['q']);
                }
            });
        }

        return $query->orderBy('id')->paginate(50);
    }

    public function save(Request $request, Workspace $workspace, string $resource, ?int $id = null)
    {
        $workspace->authorize(self::WRITE_ROLES[$resource]);
        $query = $this->query($workspace, $resource);
        $current = $id === null ? null : (clone $query)->where('id', $id)->firstOrFail();
        $data = $resource === 'products'
            ? $this->productData($workspace, $request->all(), $id)
            : $request->validate($this->rules($workspace, $resource, $id));

        return DB::transaction(function () use ($workspace, $resource, $query, $current, $id, $data) {
            DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->first();

            if ($resource === 'expenses' && isset($data['branch_id'])) {
                $workspace->branch($data['branch_id']);
                if ($current !== null && $current->branch_id !== (int) $data['branch_id']) {
                    throw ValidationException::withMessages(['branch_id' => 'An expense cannot change branches.']);
                }
            }
            if ($resource === 'customers' && isset($data['credit_limit_minor'])) {
                $workspace->authorize(['administrator']);
                if ($id !== null && (clone $query)->where('id', $id)->lockForUpdate()->firstOrFail()->balance_minor > $data['credit_limit_minor']) {
                    throw ValidationException::withMessages(['credit_limit_minor' => 'The limit is below the outstanding balance.']);
                }
            }
            if ($id === null) {
                $newId = DB::table($resource)->insertGetId($data + ['shop_id' => $workspace->shop]);

                return response()->json($query->where('id', $newId)->firstOrFail(), 201);
            }

            $query->where('id', $id);
            if ($data !== []) {
                $query->update($data);
            }

            return response()->json($query->firstOrFail());
        });
    }

    public function productData(Workspace $workspace, array $input, ?int $id = null): array
    {
        $required = $id === null ? 'required' : 'sometimes|required';
        $unique = fn (string $column) => $this->unique($workspace, 'products', $column, $id);

        return validator($input, [
            'name' => "$required|string|max:160",
            'sku' => [$required, 'string', 'max:80', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:120', $unique('barcode')],
            'category' => 'nullable|string|max:120',
            'variant' => 'nullable|string|max:120',
            'price_minor' => "$required|integer|between:0,1000000000",
            'cost_minor' => "$required|integer|between:0,1000000000",
        ])->validate();
    }

    private function query(Workspace $workspace, string $resource)
    {
        if ($resource === 'expenses') {
            $workspace->authorize(Workspace::FINANCE);

            return $workspace->operations('expenses');
        }

        $query = $workspace->records($resource);
        if ($resource === 'branches' && $workspace->user->branch_id !== null) {
            $query->where('id', $workspace->user->branch_id);
        }
        if ($resource === 'customers' && ! in_array($workspace->user->role, Workspace::FINANCE, true)) {
            $query->select('id', 'shop_id', 'name', 'email', 'phone', 'address');
        }
        if ($resource === 'products' && in_array($workspace->user->role, ['cashier', 'teller'], true)) {
            $query->select('id', 'shop_id', 'name', 'sku', 'barcode', 'category', 'variant', 'price_minor');
        }

        return $query;
    }

    public function rules(Workspace $workspace, string $resource, ?int $id): array
    {
        $required = $id === null ? 'required' : 'sometimes|required';
        $contact = [
            'name' => "$required|string|max:160",
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:60',
            'address' => 'nullable|string|max:255',
        ];

        return match ($resource) {
            'branches' => [
                'name' => [$required, 'string', 'max:160', $this->unique($workspace, 'branches', 'name', $id)],
                'address' => 'nullable|string|max:255',
            ],
            'suppliers' => $contact,
            'customers' => $contact + ['credit_limit_minor' => 'sometimes|required|integer|between:0,100000000000'],
            'expenses' => [
                'branch_id' => [$required, 'integer', $workspace->exists('branches')],
                'description' => "$required|string|max:255",
                'amount_minor' => "$required|integer|between:1,100000000000",
                'incurred_on' => "$required|date_format:Y-m-d",
            ],
        };
    }

    private function unique(Workspace $workspace, string $table, string $column, ?int $id)
    {
        $rule = Rule::unique($table, $column)->where('shop_id', $workspace->shop);

        return $id === null ? $rule : $rule->ignore($id);
    }
}
