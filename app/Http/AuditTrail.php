<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AuditTrail
{
    public static function record(
        User $actor,
        string $action,
        ?string $subject = null,
        ?int $subjectId = null,
        ?int $branchId = null,
        array $details = [],
        ?int $shopId = null,
    ): void {
        DB::table('audit_events')->insert([
            'shop_id' => $shopId ?? $actor->shop_id,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => $action,
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'branch_id' => $branchId ?? $actor->branch_id,
            'details' => json_encode((object) $details, JSON_THROW_ON_ERROR),
        ]);
    }
}
