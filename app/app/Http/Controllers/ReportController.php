<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\Device;
use App\Models\FtpAccount;
use App\Reports\ArtifactReportQuery;
use App\Reports\DeviceReportQuery;
use App\Reports\ExecutionReportQuery;
use App\Reports\FailureReportQuery;
use App\Reports\FtpReportQuery;
use App\Services\AuditEvents;
use App\Services\CsvExporter;
use App\Services\InstanceTimezone;
use App\Support\HealthStatus;
use App\Support\OperationalLabels;
use App\Support\Rbac;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('reports.view');

        $canViewAudit = $request->user()->can('audit.view');
        $hasAuditEvents = Schema::hasTable('audit_events');
        $deviceCount = Device::query()->count();
        $counts = [
            'executions' => BackupExecution::query()->count(),
            'devices' => $deviceCount,
            'documentation' => $deviceCount,
            'failures' => BackupExecution::query()->where('status', 'failed')->count(),
            'health' => $deviceCount,
            'artifacts' => BackupArtifact::query()->count(),
            'ftp' => FtpAccount::query()->count(),
            'audit' => $canViewAudit && $hasAuditEvents ? AuditEvent::query()->count() : 0,
        ];
        $recentReports = collect();

        if ($hasAuditEvents) {
            $recentReports = AuditEvent::query()
                ->with('actor:id,name')
                ->where('action', 'report.exported')
                ->where('resource_type', 'report')
                ->whereIn('resource_id', $request->user()->hasRole(Rbac::ROLE_ADMIN)
                    ? ['executions', 'devices', 'documentation', 'failures', 'artifacts', 'ftp']
                    : ['executions', 'devices', 'failures', 'artifacts', 'ftp'])
                ->when(! $canViewAudit, fn ($query) => $query->where('actor_user_id', $request->user()->id))
                ->latest('created_at')
                ->latest('id')
                ->limit(3)
                ->get();
        }

        return view('reports.index', compact('counts', 'recentReports'));
    }

    // --- Executions ---------------------------------------------------

    public function executions(Request $request, ExecutionReportQuery $query): View
    {
        $this->authorize('reports.view');
        $filters = $this->validatePeriodFilters($request, [
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'backup_policy_id' => ['nullable', 'integer', 'exists:backup_policies,id'],
            'status' => ['nullable', Rule::in(BackupExecution::STATUSES)],
            'method' => ['nullable', 'string', 'max:30'],
            'error_code' => ['nullable', 'string', 'max:100'],
        ]);

        $executions = $query->filtered($filters)->paginate(25)->withQueryString();
        $summary = $query->summary($filters);

        return view('reports.executions', compact('executions', 'summary', 'filters'));
    }

    public function executionsExport(Request $request, ExecutionReportQuery $query, CsvExporter $csv, AuditEvents $audit): StreamedResponse
    {
        $this->authorize('reports.export');
        $filters = $this->validatePeriodFilters($request, [
            'site_id' => ['nullable', 'integer'], 'device_id' => ['nullable', 'integer'],
            'vendor' => ['nullable', 'string', 'max:100'], 'backup_policy_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'], 'method' => ['nullable', 'string', 'max:30'],
            'error_code' => ['nullable', 'string', 'max:100'],
        ]);
        $timezone = app(InstanceTimezone::class);

        $rows = function () use ($query, $filters, $timezone) {
            foreach ($query->exportRows($filters) as $execution) {
                yield [
                    $timezone->format($execution->created_at),
                    $execution->device?->site?->name,
                    $execution->device?->name,
                    $execution->backupPolicy?->name,
                    OperationalLabels::METHODS[$execution->backupPolicy?->method ?? ''] ?? $execution->backupPolicy?->method,
                    OperationalLabels::EXECUTION_STATUSES[$execution->status] ?? $execution->status,
                    $execution->durationSeconds() ?? '',
                    $execution->attempt,
                    $execution->artifact?->size_bytes,
                    $execution->error_code,
                ];
            }
        };

        return $csv->stream('executions.csv',
            ['Data/Hora', 'Site', 'Equipamento', 'Política', 'Método', 'Status', 'Duração (s)', 'Tentativa', 'Tamanho do artefato (bytes)', 'Código de erro'],
            $rows(),
            fn (int $rowCount) => $this->auditExport($audit, 'executions', $filters, $rowCount));
    }

    // --- Devices --------------------------------------------------------

    public function devices(Request $request, DeviceReportQuery $query): View
    {
        $this->authorize('reports.view');
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['healthy', 'warning', 'critical', 'unknown'])],
            'policy' => ['nullable', Rule::in(['with', 'without'])],
            'freshness' => ['nullable', Rule::in(['never', 'delayed'])],
        ]);

        $devices = $query->paginate($filters, 25);

        return view('reports.devices', compact('devices', 'filters'));
    }

    public function devicesExport(Request $request, DeviceReportQuery $query, CsvExporter $csv, AuditEvents $audit): StreamedResponse
    {
        $this->authorize('reports.export');
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer'], 'vendor' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'], 'policy' => ['nullable', 'string'], 'freshness' => ['nullable', 'string'],
        ]);
        $timezone = app(InstanceTimezone::class);
        $rows = $query->filtered($filters);

        $csvRows = $rows->lazy()->map(fn ($row) => [
            $row['name'], $row['vendor'], $row['model'], OperationalLabels::METHODS[$row['method'] ?? ''] ?? $row['method'], $row['policy_name'],
            $timezone->format($row['last_backup_at'] ? CarbonImmutable::parse($row['last_backup_at']) : null),
            $timezone->format($row['last_success_at'] ? CarbonImmutable::parse($row['last_success_at']) : null),
            $timezone->format($row['last_failure_at'] ? CarbonImmutable::parse($row['last_failure_at']) : null),
            HealthStatus::tryFrom($row['status'])?->label() ?? $row['status'], $row['consecutive_failures'], $row['latest_artifact_size'],
        ]);

        return $csv->stream('devices.csv',
            ['Equipamento', 'Fabricante', 'Modelo', 'Método', 'Política', 'Último backup', 'Último sucesso', 'Última falha', 'Saúde', 'Falhas consecutivas', 'Tamanho último backup (bytes)'],
            $csvRows,
            fn (int $rowCount) => $this->auditExport($audit, 'devices', $filters, $rowCount));
    }

    // --- Artifacts --------------------------------------------------------

    public function artifacts(Request $request, ArtifactReportQuery $query): View
    {
        $this->authorize('reports.view');
        $filters = $this->validatePeriodFilters($request, [
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'status' => ['nullable', Rule::in(BackupArtifact::STATUSES)],
            'min_size' => ['nullable', 'integer', 'min:0'],
            'max_size' => ['nullable', 'integer', 'min:0'],
        ]);

        $artifacts = $query->filtered($filters)->paginate(25)->withQueryString();

        return view('reports.artifacts', compact('artifacts', 'filters'));
    }

    public function artifactsExport(Request $request, ArtifactReportQuery $query, CsvExporter $csv, AuditEvents $audit): StreamedResponse
    {
        $this->authorize('reports.export');
        $filters = $this->validatePeriodFilters($request, [
            'site_id' => ['nullable', 'integer'], 'device_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'], 'min_size' => ['nullable', 'integer'], 'max_size' => ['nullable', 'integer'],
        ]);
        $timezone = app(InstanceTimezone::class);

        $rows = function () use ($query, $filters, $timezone) {
            foreach ($query->exportRows($filters) as $artifact) {
                yield [
                    $artifact->device?->site?->name, $artifact->device?->name,
                    $artifact->backup_execution_id, $artifact->original_filename, $artifact->size_bytes,
                    substr($artifact->sha256 ?? '', 0, 12), $timezone->format($artifact->created_at),
                    OperationalLabels::ARTIFACT_STATUSES[$artifact->status] ?? $artifact->status, $artifact->storage,
                ];
            }
        };

        return $csv->stream('artifacts.csv',
            ['Site', 'Equipamento', 'Execução', 'Arquivo', 'Tamanho (bytes)', 'SHA256 (abrev.)', 'Criado em', 'Status', 'Armazenamento'],
            $rows(),
            fn (int $rowCount) => $this->auditExport($audit, 'artifacts', $filters, $rowCount));
    }

    // --- Failures --------------------------------------------------------

    public function failures(Request $request, FailureReportQuery $query): View
    {
        $this->authorize('reports.view');
        $filters = $this->validatePeriodFilters($request, [
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'vendor' => ['nullable', 'string', 'max:100'],
        ]);

        $byErrorCode = $query->byErrorCode($filters);
        $byDevice = $query->byDevice($filters);

        return view('reports.failures', compact('byErrorCode', 'byDevice', 'filters'));
    }

    public function failuresExport(Request $request, FailureReportQuery $query, CsvExporter $csv, AuditEvents $audit): StreamedResponse
    {
        $this->authorize('reports.export');
        $filters = $this->validatePeriodFilters($request, [
            'site_id' => ['nullable', 'integer'], 'vendor' => ['nullable', 'string', 'max:100'],
        ]);
        $timezone = app(InstanceTimezone::class);
        $rows = $query->byErrorCode($filters);

        $csvRows = collect($rows)->lazy()->map(fn ($row) => [
            $row['error_code'], $row['total'], $row['retryable'] ? 'sim' : 'não',
            $timezone->format(CarbonImmutable::parse($row['first_seen_at'])),
            $timezone->format(CarbonImmutable::parse($row['last_seen_at'])),
        ]);

        return $csv->stream('failures.csv',
            ['Código de erro', 'Ocorrências', 'Permite nova tentativa', 'Primeira ocorrência', 'Última ocorrência'],
            $csvRows,
            fn (int $rowCount) => $this->auditExport($audit, 'failures', $filters, $rowCount));
    }

    // --- FTP --------------------------------------------------------

    public function ftp(FtpReportQuery $query): View
    {
        $this->authorize('reports.view');
        $backupAccounts = $query->accounts('backup');
        $fileServerAccounts = $query->accounts('file_server');

        return view('reports.ftp', compact('backupAccounts', 'fileServerAccounts'));
    }

    public function ftpExport(FtpReportQuery $query, CsvExporter $csv, AuditEvents $audit): StreamedResponse
    {
        $this->authorize('reports.export');
        $timezone = app(InstanceTimezone::class);
        $rows = collect($query->accounts('backup'))->map(fn ($r) => $r + ['purpose' => 'backup'])
            ->merge(collect($query->accounts('file_server'))->map(fn ($r) => $r + ['purpose' => 'file_server']));

        $csvRows = $rows->lazy()->map(fn ($row) => [
            $row['purpose'] === 'backup' ? 'Backup' : 'Servidor de arquivos', $row['device_name'] ?? $row['username'], OperationalLabels::FTP_LAYOUTS[$row['home_layout']] ?? $row['home_layout'],
            $row['is_active'] ? 'sim' : 'não',
            $row['last_received_at'] ? $timezone->format(CarbonImmutable::parse($row['last_received_at'])) : '',
            $row['stored_count'], $row['quarantined_count'], $row['stuck_count'],
        ]);

        return $csv->stream('ftp.csv',
            ['Tipo', 'Conta/Equipamento', 'Organização dos arquivos', 'Ativa', 'Último recebimento', 'Armazenados', 'Quarentena', 'Presos em processamento'],
            $csvRows,
            fn (int $rowCount) => $this->auditExport($audit, 'ftp', [], $rowCount));
    }

    // --- shared --------------------------------------------------------

    private function validatePeriodFilters(Request $request, array $extra): array
    {
        return $request->validate(array_merge([
            'period' => ['nullable', Rule::in(ReportPeriod::OPTIONS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ], $extra));
    }

    /** report.exported audit event — filters/row_count/format only, never the exported content itself. */
    private function auditExport(AuditEvents $audit, string $reportType, array $filters, int $rowCount): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }
        $audit->record('report.exported', 'report', $reportType, $reportType, 'success', [
            'report_type' => $reportType, 'filters' => $filters, 'row_count' => $rowCount, 'format' => 'csv',
        ], auth()->id(), request()->ip());
    }
}
