<?php

namespace App\Http\Controllers;

use App\Http\Resources\Api\V1\BackupExecutionResource;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\Device;
use App\Models\Site;
use App\Reports\DeviceReportQuery;
use App\Reports\ExecutionReportQuery;
use App\Services\DeviceBackupHealth;
use App\Services\EngineHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class IntegrationApiController extends Controller
{
    public function health(EngineHealth $health): JsonResponse
    {
        $report = $health->report();

        return response()->json(['data' => [
            'status' => $report['overall_status'],
            'checked_at' => $report['checked_at'],
        ]]);
    }

    public function summary(DeviceBackupHealth $health, ExecutionReportQuery $executions): JsonResponse
    {
        $deviceCounts = $health->summary(0)['counts'];
        $today = $executions->summary(['period' => 'today']);

        return response()->json(['data' => [
            'sites' => Site::query()->count(),
            'devices' => Device::query()->count(),
            'active_device_health' => $deviceCounts,
            'backups_today' => [
                'succeeded' => $today['succeeded'],
                'failed' => $today['failed'],
                'timed_out' => $today['timed_out'],
                'pending_or_running' => $today['pending_or_running'],
            ],
            'generated_at' => now()->toIso8601String(),
        ]]);
    }

    public function devices(Request $request, DeviceReportQuery $devices): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['healthy', 'warning', 'critical', 'unknown'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 25);

        return DeviceResource::collection($devices->paginate($filters, $perPage, $filters));
    }

    public function recentBackups(Request $request, ExecutionReportQuery $executions): AnonymousResourceCollection
    {
        $filters = $this->listFilters($request);
        $query = $executions->filtered($filters + ['status' => 'succeeded'])
            ->select(['id', 'device_id', 'backup_policy_id', 'status', 'origin', 'error_code', 'created_at', 'finished_at']);

        return BackupExecutionResource::collection($query->paginate((int) ($filters['per_page'] ?? 25))
            ->appends($request->only(['period', 'site_id', 'device_id', 'per_page'])));
    }

    public function failures(Request $request, ExecutionReportQuery $executions): AnonymousResourceCollection
    {
        $filters = $this->listFilters($request);
        $query = $executions->filtered($filters)
            ->whereIn('backup_executions.status', ExecutionReportQuery::TERMINAL_FAILURE)
            ->select(['id', 'device_id', 'backup_policy_id', 'status', 'origin', 'error_code', 'created_at', 'finished_at']);

        return BackupExecutionResource::collection($query->paginate((int) ($filters['per_page'] ?? 25))
            ->appends($request->only(['period', 'site_id', 'device_id', 'per_page'])));
    }

    private function listFilters(Request $request): array
    {
        return $request->validate([
            'period' => ['nullable', Rule::in(['today', '7d', '30d', '90d'])],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'device_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
    }
}
