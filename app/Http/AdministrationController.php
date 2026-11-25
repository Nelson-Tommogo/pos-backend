<?php

namespace App\Http;

use App\Jobs\SendPosEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdministrationController
{
    public function shops(Request $request)
    {
        abort_unless($request->user()->role === 'platform_admin', 403);

        return DB::table('shops')->orderBy('id')->paginate(50);
    }

    public function onboard(Request $request)
    {
        abort_unless($request->user()->role === 'platform_admin', 403);
        $data = $this->administratorData($request, ['shop_name' => 'required|string|max:160']);

        return DB::transaction(function () use ($data) {
            $shop = DB::table('shops')->insertGetId(['name' => $data['shop_name']]);
            unset($data['shop_name']);
            $administrator = $this->createAdministrator($shop, $data);

            return response()->json(['shop_id' => $shop, 'administrator' => $administrator], 201);
        });
    }

    public function addAdministrator(Request $request, int $shop)
    {
        abort_unless($request->user()->role === 'platform_admin', 403);
        DB::table('shops')->where('id', $shop)->firstOrFail();
        $administrator = $this->createAdministrator($shop, $this->administratorData($request));

        return response()->json($administrator, 201);
    }

    private function administratorData(Request $request, array $extraRules = []): array
    {
        $input = $request->all();
        if (is_string($input['email'] ?? null)) {
            $input['email'] = strtolower($input['email']);
        }

        return validator($input, [
            'name' => 'required|string|max:160',
            'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'string', 'min:12', 'regex:/\\A[^\\x00]{1,72}\\z/'],
        ] + $extraRules)->validate();
    }

    private function createAdministrator(int $shop, array $data): User
    {
        $user = User::create($data + ['shop_id' => $shop, 'role' => 'administrator', 'branch_id' => null]);
        SendPosEmail::account($user);

        return $user;
    }

    public function team(Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(['administrator', 'branch_manager', 'store_manager']);
        $query = User::where('shop_id', $workspace->shop);
        if ($workspace->user->branch_id !== null) {
            $query->where('branch_id', $workspace->user->branch_id);
        }

        return $id === null ? $query->orderBy('id')->paginate(50) : $query->findOrFail($id);
    }

    public function user(Request $request, Workspace $workspace, ?int $id = null)
    {
        $workspace->authorize(['administrator']);
        $data = $request->all();
        return DB::transaction(function () use ($workspace, $id, $data) {
            $user = $id === null ? null : User::where('shop_id', $workspace->shop)->lockForUpdate()->findOrFail($id);
            $saved = $this->saveUser($workspace, $data, $user);

            return response()->json($saved, $id === null ? 201 : 200);
        });
    }

    public function invite(Request $request, Workspace $workspace)
    {
        $workspace->authorize(['administrator']);
        $user = $this->saveUser($workspace, $request->only('name', 'email', 'role', 'branch_id') + [
            'password' => Str::random(48),
        ]);
        return response()->json($user, 201);
    }

    public function saveUser(Workspace $workspace, array $input, ?User $user = null): User
    {
        $required = $user ? 'sometimes|required' : 'required';
        if (is_string($input['email'] ?? null)) {
            $input['email'] = strtolower($input['email']);
        }
        $email = Rule::unique('users', 'email');
        if ($user) {
            $email->ignore($user->id);
        }
        $data = validator($input, [
            'name' => "$required|string|max:160",
            'email' => [$required, 'email', 'max:255', $email],
            'password' => [$required, 'string', 'min:12', 'regex:/\\A[^\\x00]{1,72}\\z/'],
            'role' => [$required, Rule::in(Workspace::ROLES)],
            'branch_id' => ['nullable', 'integer', $workspace->exists('branches')],
        ])->validate();
        $role = $data['role'] ?? $user?->role;
        $branch = array_key_exists('branch_id', $data) ? $data['branch_id'] : $user?->branch_id;
        if ($role !== 'administrator' && $branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'A branch is required for this role.']);
        }
        if ($user?->id === $workspace->user->id && $role !== 'administrator') {
            throw ValidationException::withMessages(['role' => 'You cannot remove your own administrator role.']);
        }
        $data['branch_id'] = $role === 'administrator' ? null : $branch;

        if ($user === null) {
            $user = User::create($data + ['shop_id' => $workspace->shop]);
            SendPosEmail::account($user);

            return $user;
        }

        $user->update($data);
        if ($user->wasChanged(['password', 'role', 'branch_id', 'email'])) {
            $user->tokens()->delete();
        }

        return $user;
    }

    public function settings(Workspace $workspace)
    {
        $shop = $workspace->settings();
        $shop->receipt = json_decode($shop->receipt);

        return response()->json($shop);
    }

    public function updateSettings(Request $request, Workspace $workspace)
    {
        $workspace->authorize(['administrator']);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'currency' => 'sometimes|required|regex:/^[A-Z]{3}$/',
            'tax_bps' => 'sometimes|required|integer|between:0,10000',
            'receipt' => 'sometimes|array:name,address,phone,footer,accent',
            'receipt.*' => 'nullable|string|max:255',
            'receipt.accent' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);
        DB::transaction(function () use ($workspace, $data): void {
            $shop = DB::table('shops')->where('id', $workspace->shop);
            $current = (clone $shop)->lockForUpdate()->first();
            if (isset($data['currency']) && $data['currency'] !== $current->currency
                && ($workspace->records('sales')->exists() || $workspace->records('payments')->exists()
                    || $workspace->records('expenses')->exists() || $workspace->records('purchases')->exists()
                    || $workspace->records('products')->exists() || $workspace->records('customers')->where('credit_limit_minor', '>', 0)->exists())) {
                throw ValidationException::withMessages(['currency' => 'Currency can only change before monetary records exist.']);
            }
            if (isset($data['receipt'])) {
                $receipt = array_replace((array) json_decode($current->receipt), $data['receipt']);
                $data['receipt'] = json_encode((object) $receipt, JSON_THROW_ON_ERROR);
            }
            if ($data !== []) {
                $shop->update($data);
            }
        });

        return $this->settings($workspace);
    }

    public function preferences(Request $request)
    {
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(['meadow', 'ocean', 'plum', 'graphite', 'dark'])],
            'paper_mm' => 'sometimes|integer|in:58,80,210',
            'print_after_checkout' => 'sometimes|boolean',
        ]);
        return DB::transaction(function () use ($request, $data): object {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $user->update(['preferences' => (object) array_replace($user->preferences, $data)]);

            return (object) $user->preferences;
        });
    }
}
