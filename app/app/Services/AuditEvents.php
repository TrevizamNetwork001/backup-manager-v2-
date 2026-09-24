<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AuditEvents
{
    public function record(string $action, string $resourceType, ?string $resourceId, ?string $label,
        string $result, array $metadata, ?int $actorId = null, ?string $ip = null): void
    {
        DB::table('audit_events')->insert([
            'actor_user_id' => $actorId, 'action' => $action, 'resource_type' => $resourceType,
            'resource_id' => $resourceId, 'resource_label' => $label, 'result' => $result,
            'ip_address' => $ip, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
