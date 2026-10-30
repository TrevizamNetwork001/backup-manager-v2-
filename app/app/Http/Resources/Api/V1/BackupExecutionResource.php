<?php

namespace App\Http\Resources\Api\V1;

use App\Support\ErrorCodes;
use App\Support\OperationalLabels;
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
                'method_label' => OperationalLabels::METHODS[$this->backupPolicy?->method] ?? $this->backupPolicy?->method,
            ],
            'status' => $this->status,
            'status_label' => OperationalLabels::EXECUTION_STATUSES[$this->status] ?? $this->status,
            'origin' => $this->origin,
            'error_code' => $this->error_code,
            'error_label' => $this->error_code ? ErrorCodes::message($this->error_code) : null,
            'artifact_size_bytes' => $this->artifact?->size_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
