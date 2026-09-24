<?php

namespace App\Http\Controllers;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BackupExecutionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(BackupExecution::STATUSES)],
            'origin' => ['nullable', Rule::in(BackupExecution::ORIGINS)],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
        ]);
        $executions = BackupExecution::query()
            ->with(['device:id,name', 'backupPolicy:id,name'])
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['origin'] ?? null, fn ($query, $value) => $query->where('origin', $value))
            ->when($filters['device_id'] ?? null, fn ($query, $value) => $query->where('device_id', $value))
            ->latest('id')->paginate(20)->withQueryString();
        $devices = Device::query()->orderBy('name')->get(['id', 'name']);

        return view('backup-executions.index', compact('executions', 'devices', 'filters'));
    }

    public function show(BackupExecution $backupExecution): View
    {
        $backupExecution->load([
            'device:id,name', 'backupPolicy:id,name,method',
            'credential:id,name,type,username', 'artifact',
        ]);
        $contentAnalysis = null;
        if ($backupExecution->backupPolicy->method === 'ftp_push' && Schema::hasTable('audit_events')) {
            $event = DB::table('audit_events')->where('action', 'backup.content_analyzed')
                ->where('resource_type', 'backup_execution')->where('resource_id', (string) $backupExecution->id)
                ->orderByDesc('id')->first();
            $contentAnalysis = $event ? json_decode($event->metadata, true) : null;
        }
        return view('backup-executions.show', compact('backupExecution', 'contentAnalysis'));
    }

    public function storeManual(BackupPolicy $backupPolicy, DeviceBackupPolicy $association): RedirectResponse
    {
        abort_unless($association->backup_policy_id === $backupPolicy->id, 404);
        $execution = BackupExecution::createManual($association);

        return redirect()->route('backup-executions.show', $execution)
            ->with('success', 'Execução manual criada. Nenhum backup foi iniciado.');
    }

    public function queue(BackupExecution $backupExecution): RedirectResponse
    {
        return $this->transition($backupExecution, 'queued');
    }

    public function cancel(BackupExecution $backupExecution): RedirectResponse
    {
        return $this->transition($backupExecution, 'cancelled');
    }

    private function transition(BackupExecution $execution, string $status): RedirectResponse
    {
        $execution->transitionTo($status);

        return redirect()->route('backup-executions.show', $execution)
            ->with('success', 'Estado da execução atualizado para '.strtoupper($status).'.');
    }
}
