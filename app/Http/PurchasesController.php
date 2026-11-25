<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasesController
{
    public function index(Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(array_unique([...Workspace::STOCK, ...Workspace::FINANCE]));
        $query = $workspace->operations('purchases');
        if ($id !== null) {
            return response()->json($this->purchase($query->where('id', $id)->firstOrFail()));
        }

        return $query->orderByDesc('id')->paginate(50)->through($this->purchase(...));
    }

    public function save(Request $request, Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(Workspace::STOCK);
        $required = $id === null ? 'required' : 'sometimes|required';
        $data = $request->validate([
            'branch_id' => "$required|integer",
            'supplier_id' => [$required, 'integer', $workspace->exists('suppliers')],
            'items' => "$required|array|list|min:1|max:100",
            'items.*' => 'required|array:product_id,quantity,cost_minor',
            'items.*.product_id' => ['required', 'integer', 'distinct', $workspace->exists('products')],
            'items.*.quantity' => 'required|integer|between:1,1000000',
            'items.*.cost_minor' => 'required|integer|between:0,1000000000',
        ]);
        if (isset($data['branch_id'])) {
            $data['branch_id'] = $workspace->branch($data['branch_id']);
        }
        if (isset($data['items'])) {
            usort($data['items'], fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
            $data['items'] = json_encode($data['items'], JSON_THROW_ON_ERROR);
        }

        return DB::transaction(function () use ($workspace, $id, $data) {
            DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->first();
            if ($id === null) {
                $newId = DB::table('purchases')->insertGetId($data + ['shop_id' => $workspace->shop]);

                return response()->json($this->purchase($workspace->operations('purchases')->where('id', $newId)->firstOrFail()), 201);
            }
            $query = $workspace->operations('purchases')->where('id', $id);
            $purchase = (clone $query)->lockForUpdate()->firstOrFail();
            abort_if($purchase->received_at !== null, 409, 'The purchase has already been received.');
            if ($data !== []) {
                $query->update($data);
            }

            return response()->json($this->purchase($query->firstOrFail()));
        }, 3);
    }

    public function receive(Request $request, Workspace $workspace, InventoryController $inventory, int $id)
    {
        $workspace->authorize(Workspace::STOCK);

        return DB::transaction(function () use ($request, $workspace, $inventory, $id) {
            $query = $workspace->operations('purchases')->where('id', $id);
            $purchase = $this->purchase((clone $query)->lockForUpdate()->firstOrFail());
            if ($purchase->received_at === null) {
                foreach ($purchase->items as $item) {
                    $inventory->move($workspace, $purchase->branch_id, $item->product_id, $item->quantity, 'purchase', $id);
                }
                $query->update(['received_at' => now()]);
                $purchase = $this->purchase($query->firstOrFail());
            } else {
                $request->attributes->set('audit_replay', true);
            }

            return response()->json($purchase);
        }, 3);
    }

    private function purchase(object $purchase): object
    {
        $purchase->items = json_decode($purchase->items);

        return $purchase;
    }
}
