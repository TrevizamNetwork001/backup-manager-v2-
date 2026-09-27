<?php

namespace App\Http\Controllers;

use App\Reports\ArtifactReportQuery;
use App\Reports\DeviceReportQuery;
use App\Reports\ExecutionReportQuery;
use App\Reports\FailureReportQuery;
use App\Reports\FtpReportQuery;
use App\Services\AuditEvents;
use App\Services\CsvExporter;
use App\Services\InstanceTimezone;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        $this->authorize('reports.view');

        return view('reports.index');
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
            'status' => ['nullable', Rule::in(\App\Models\BackupExecution::STATUSES)],
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
                    $execution->backupPolicy?->method,
                    $execution->status,
                    $execution->started_at && $execution->finished_at
                        ? $execution->started_at->diffInSeconds($execution->finished_at) : '',
                    $execution->attempt,
                    $execution->artifact?->size_bytes,
                    $execution->error_code,
                ];
            }
        };

        return $csv->stream('executions.csv',
            ['Data/Hora', 'Site', 'Equipamento', 'Política', 'Método', 'Status', 'Duração (s)', 'Tentativa', 'Tamanho do artifact (bytes)', 'Código de erro'],
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
            $row['name'], $row['vendor'], $row['model'], $row['method'], $row['policy_name'],
            $timezone->format($row['last_backup_at'] ? \Carbon\CarbonImmutable::parse($row['last_backup_at']) : null),
            $timezone->format($row['last_success_at'] ? \Carbon\CarbonImmutable::parse($row['last_success_at']) : null),
            $timezone->format($row['last_failure_at'] ? \Carbon\CarbonImmutable::parse($row['last_failure_at']) : null),
            $row['status'], $row['consecutive_failures'], $row['latest_artifact_size'],
        ]);

        return $csv->stream('devices.csv',
            ['Equipamento', 'Vendor', 'Modelo', 'Método', 'Política', 'Último backup', 'Último sucesso', 'Última falha', 'Saúde', 'Falhas consecutivas', 'Tamanho último backup (bytes)'],
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
            'status' => ['nullable', Rule::in(\App\Models\BackupArtifact::STATUSES)],
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
                    $artifact->status, $artifact->storage,
                ];
            }
        };

        return $csv->stream('artifacts.csv',
            ['Site', 'Equipamento', 'Execução', 'Arquivo', 'Tamanho (bytes)', 'SHA256 (abrev.)', 'Criado em', 'Status', 'Storage'],
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
            $timezone->format(\Carbon\CarbonImmutable::parse($row['first_seen_at'])),
            $timezone->format(\Carbon\CarbonImmutable::parse($row['last_seen_at'])),
        ]);

        return $csv->stream('failures.csv',
            ['Código de erro', 'Ocorrências', 'Retryable', 'Primeira ocorrência', 'Última ocorrência'],
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
            $row['purpose'], $row['device_name'] ?? $row['username'], $row['home_layout'],
            $row['is_active'] ? 'sim' : 'não',
            $row['last_received_at'] ? $timezone->format(\Carbon\CarbonImmutable::parse($row['last_received_at'])) : '',
            $row['stored_count'], $row['quarantined_count'], $row['stuck_count'],
        ]);

        return $csv->stream('ftp.csv',
            ['Tipo', 'Conta/Equipamento', 'Layout', 'Ativa', 'Último recebimento', 'Armazenados', 'Quarentena', 'Presos em processamento'],
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
