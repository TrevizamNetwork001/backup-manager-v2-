<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device' => [
                'id' => $this->device?->id,
                'name' => $this->device?->name,
                'site_id' => $this->device?->site_id,
            ],
            'policy' => [
                'id' => $this->backupPolicy?->id,
                'name' => $this->backupPolicy?->name,
                'method' => $this->backupPolicy?->method,
            ],
            'status' => $this->status,
            'origin' => $this->origin,
            'error_code' => $this->error_code,
            'artifact_size_bytes' => $this->artifact?->size_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
