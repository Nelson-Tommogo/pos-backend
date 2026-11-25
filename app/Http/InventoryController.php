<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController
{
    public function index(Request $request, Workspace $workspace)
    {
        $data = $request->validate([
            'branch_id' => 'sometimes|integer',
            'product_id' => 'sometimes|integer',
            'low_stock' => 'sometimes|boolean',
        ]);
        $query = $workspace->operations('inventory');
        foreach (['branch_id', 'product_id'] as $column) {
            if (isset($data[$column])) {
                $query->where($column, $data[$column]);
            }
        }
        if ($data['low_stock'] ?? false) {
            $query->whereColumn('quantity', '<=', 'reorder_level');
        }

        return $query->orderBy('branch_id')->orderBy('product_id')->paginate(50);
    }

    public function configure(Request $request, Workspace $workspace, int $branch, int $product)
    {
        $workspace->authorize(Workspace::STOCK);
        $workspace->branch($branch);
        abort_unless($workspace->records('products')->where('id', $product)->exists(), 404);
        $data = $request->validate(['reorder_level' => 'required|integer|between:0,1000000']);
        $key = $this->key($workspace, $branch, $product);
        DB::table('inventory')->upsert([$key + $data], array_keys($key), ['reorder_level']);

        return $this->show($workspace, $branch, $product);
    }

    public function show(Workspace $workspace, int $branch, int $product)
    {
        $workspace->branch($branch);

        return response()->json(DB::table('inventory')->where($this->key($workspace, $branch, $product))->firstOrFail());
    }

    public function movements(Workspace $workspace)
    {
        $workspace->authorize(Workspace::STOCK);

        return $workspace->operations('stock_movements')->orderByDesc('id')->paginate(50);
    }

    public function adjust(Request $request, Workspace $workspace)
    {
        $workspace->authorize(Workspace::STOCK);
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'product_id' => ['required', 'integer', $workspace->exists('products')],
            'delta' => 'required|integer|between:-1000000,1000000|not_in:0',
            'note' => 'required|string|max:255',
        ]);
        $branch = $workspace->branch($data['branch_id']);

        return DB::transaction(function () use ($workspace, $branch, $data) {
            $this->move($workspace, $branch, $data['product_id'], $data['delta'], 'adjustment', note: $data['note']);

            return $this->show($workspace, $branch, $data['product_id']);
        }, 3);
    }

    public function transfer(Request $request, Workspace $workspace)
    {
        $workspace->authorize(['administrator']);
        $data = $request->validate([
            'from_branch_id' => 'required|integer',
            'to_branch_id' => 'required|integer|different:from_branch_id',
            'product_id' => ['required', 'integer', $workspace->exists('products')],
            'quantity' => 'required|integer|between:1,1000000',
            'note' => 'nullable|string|max:255',
        ]);
        $from = $workspace->branch($data['from_branch_id']);
        $to = $workspace->branch($data['to_branch_id']);
        if ($from === $to) {
            throw ValidationException::withMessages(['to_branch_id' => 'Choose a different destination branch.']);
        }

        DB::transaction(function () use ($workspace, $from, $to, $data): void {
            $out = $this->move($workspace, $from, $data['product_id'], -$data['quantity'], 'transfer_out', note: $data['note'] ?? null);
            $in = $this->move($workspace, $to, $data['product_id'], $data['quantity'], 'transfer_in', $out, $data['note'] ?? null);
            $workspace->records('stock_movements')->where('id', $out)->update(['reference_id' => $in]);
        }, 3);

        return response()->noContent();
    }

    public function move(Workspace $workspace, int $branch, int $product, int $delta, string $reason, ?int $reference = null, ?string $note = null): int
    {
        $key = $this->key($workspace, $branch, $product);
        DB::table('inventory')->insertOrIgnore($key);
        $query = DB::table('inventory')->where($key);
        $quantity = (clone $query)->lockForUpdate()->first()->quantity + $delta;
        if ($quantity < 0 || $quantity > 2147483647) {
            throw ValidationException::withMessages(['quantity' => "Stock for product $product would be outside the supported range."]);
        }
        $query->update(['quantity' => $quantity]);

        return DB::table('stock_movements')->insertGetId($key + [
            'user_id' => $workspace->user->id,
            'delta' => $delta,
            'reason' => $reason,
            'reference_id' => $reference,
            'note' => $note,
        ]);
    }

    private function key(Workspace $workspace, int $branch, int $product): array
    {
        return ['shop_id' => $workspace->shop, 'branch_id' => $branch, 'product_id' => $product];
    }
}
