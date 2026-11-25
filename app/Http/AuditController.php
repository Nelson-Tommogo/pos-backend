<?php

namespace App\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, ['administrator', 'platform_admin'], true), 403);
        $data = $request->validate([
            'from' => 'sometimes|required|date_format:Y-m-d',
            'to' => 'sometimes|required|date_format:Y-m-d',
            'actor_id' => 'sometimes|required|integer|min:1',
            'action' => 'sometimes|required|string|max:100',
            'branch_id' => 'sometimes|required|integer|min:1',
            'subject_type' => 'sometimes|required|string|max:40',
            'subject_id' => 'sometimes|required|integer|min:1',
        ]);
        $query = DB::table('audit_events');
        if ($actor->role === 'platform_admin') {
            $query->where('actor_role', 'platform_admin');
        } else {
            $query->where('shop_id', $actor->shop_id);
        }
        foreach (['actor_id', 'action', 'branch_id', 'subject_type', 'subject_id'] as $column) {
            if (isset($data[$column])) {
                $query->where($column, $data[$column]);
            }
        }
        if (isset($data['from'], $data['to']) && $data['from'] > $data['to']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'The end date must follow the start date.']);
        }
        if (isset($data['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($data['from'], 'UTC')->startOfDay());
        }
        if (isset($data['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($data['to'], 'UTC')->startOfDay()->addDay());
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50)->withQueryString()
            ->through(function (object $event): object {
                $event->details = json_decode($event->details, flags: JSON_THROW_ON_ERROR);

                return $event;
            });
    }
}
