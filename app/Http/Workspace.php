<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Workspace
{
    public const ROLES = ['administrator', 'branch_manager', 'store_manager', 'cashier', 'teller', 'accountant', 'stock_clerk'];
    public const SALES = ['administrator', 'branch_manager', 'store_manager', 'cashier', 'teller'];
    public const STOCK = ['administrator', 'branch_manager', 'store_manager', 'stock_clerk'];
    public const FINANCE = ['administrator', 'branch_manager', 'store_manager', 'accountant'];
    public const METHODS = ['cash', 'mpesa', 'card', 'bank_transfer'];

    public User $user;
    public int $shop;

    public function __construct(Request $request)
    {
        $this->user = $request->user();
        abort_unless($this->user->shop_id, 403);
        $this->shop = $this->user->shop_id;
    }

    public function authorize(array $roles): void
    {
        abort_unless(in_array($this->user->role, $roles, true), 403);
    }

    public function records(string $table): Builder
    {
        return DB::table($table)->where($table.'.shop_id', $this->shop);
    }

    public function operations(string $table): Builder
    {
        $query = $this->records($table);
        if ($this->user->branch_id !== null) {
            $query->where('branch_id', $this->user->branch_id);
        }

        return $query;
    }

    public function branch(int $branch): int
    {
        abort_if($this->user->branch_id !== null && $this->user->branch_id !== $branch, 403);
        abort_unless($this->records('branches')->where('id', $branch)->exists(), 404);

        return $branch;
    }

    public function exists(string $table)
    {
        return Rule::exists($table, 'id')->where('shop_id', $this->shop);
    }

    public function settings(): object
    {
        return DB::table('shops')->where('id', $this->shop)->first();
    }
}
