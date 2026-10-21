<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['device_id'],
            'name' => $this->resource['name'],
            'site_id' => $this->resource['site_id'],
            'vendor' => $this->resource['vendor'],
            'model' => $this->resource['model'],
            'backup_status' => $this->resource['status'],
            'backup_status_reason' => $this->resource['reason'],
            'policy_names' => $this->resource['policy_names'],
            'last_backup_at' => $this->resource['last_backup_at']
                ? CarbonImmutable::parse($this->resource['last_backup_at'], 'UTC')->toIso8601String() : null,
            'last_success_at' => $this->resource['last_success_at']
                ? CarbonImmutable::parse($this->resource['last_success_at'], 'UTC')->toIso8601String() : null,
        ];
    }
}
