<?php

namespace App\Http\Controllers;

use App\Models\BackupArtifact;
use App\Models\Device;
use App\Models\Site;
use App\Services\ArtifactDeletionService;
use App\Services\ArtifactStorage;
use App\Support\DestructiveMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupArtifactController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('backup_artifacts.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'period' => ['nullable', 'in:7,30,90,all'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:available,deleted,missing'],
            'artifact' => ['nullable', 'integer'],
        ]);

        $query = BackupArtifact::query()->with(['device:id,name,management_ip,vendor,platform,model,site_id', 'device.site:id,name', 'backupPolicy:id,name']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($query) use ($search) {
                $query->where('original_filename', 'like', "%{$search}%")
                    ->orWhereHas('device', function ($device) use ($search) {
                        $device->where('name', 'like', "%{$search}%")
                            ->orWhere('management_ip', 'like', "%{$search}%")
                            ->orWhereHas('site', fn ($site) => $site->where('name', 'like', "%{$search}%"));
                    });
            });
        }
        if (($filters['period'] ?? '30') !== 'all') {
            $query->where('created_at', '>=', now()->subDays((int) ($filters['period'] ?? 30)));
        }
        if (! empty($filters['site_id'])) {
            $query->whereHas('device', fn ($device) => $device->where('site_id', $filters['site_id']));
        }
        if (! empty($filters['vendor'])) {
            $query->whereHas('device', fn ($device) => $device->where('vendor', $filters['vendor']));
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $artifacts = $query->latest('id')->paginate(10)->appends($request->except(['artifact', 'page']));
        $selectedArtifact = $artifacts->getCollection()->firstWhere('id', (int) ($filters['artifact'] ?? 0));
        $statusCounts = BackupArtifact::query()->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $sites = Site::query()->orderBy('name')->get(['id', 'name']);
        $vendors = Device::query()->whereNotNull('vendor')->distinct()->orderBy('vendor')->pluck('vendor');
        $types = BackupArtifact::query()->distinct()->orderBy('type')->pluck('type');

        return view('backup-artifacts.index', compact('artifacts', 'selectedArtifact', 'statusCounts', 'sites', 'vendors', 'types', 'filters'));
    }

    public function show(BackupArtifact $backupArtifact, ArtifactDeletionService $service): View
    {
        $this->authorize('backup_artifacts.view');
        $backupArtifact->load([
            'device:id,name,management_ip,vendor,platform,model,site_id', 'device.site:id,name',
            'backupPolicy:id,name', 'backupExecution.device:id,name',
        ]);

        $preview = null;
        $confirmationPhrase = null;
        if (auth()->user()->can('backup_artifacts.delete')) {
            $preview = $service->preview($backupArtifact);
            $confirmationPhrase = DestructiveMode::confirmationPhrase(DestructiveMode::DELETE, $preview->resourceLabel);
        }

        return view('backup-artifacts.show', compact('backupArtifact', 'preview', 'confirmationPhrase'));
    }

    /**
     * FEATURES-FINAL-1 Part P: the `backup_artifacts.download` permission has
     * existed since ADMIN-2 with no route to back it — this closes that gap.
     * Reuses ArtifactStorage::verify() (the same path-safety/content-integrity
     * primitive retention and manual deletion already use) rather than
     * reading `relative_path` directly — a missing/tampered/renamed-device
     * artifact never reaches the filesystem call.
     */
    public function download(BackupArtifact $backupArtifact, ArtifactStorage $storage): BinaryFileResponse
    {
        $this->authorize('backup_artifacts.download');
        $backupArtifact->load('backupExecution.device');

        $check = $storage->verify($backupArtifact);
        if ($check['result'] !== 'valid') {
            abort(404, 'Arquivo do artifact não está disponível.');
        }

        $extension = pathinfo($backupArtifact->relative_path, PATHINFO_EXTENSION) ?: 'bin';
        $stem = pathinfo($backupArtifact->original_filename ?: ('artifact-'.$backupArtifact->id), PATHINFO_FILENAME);
        $filename = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem), '-');
        $filename = ($filename ?: 'artifact-'.$backupArtifact->id).'.'.$extension;

        $response = response()->download($check['path'], $filename, [
            'Content-Type' => 'application/octet-stream',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function destroy(Request $request, BackupArtifact $backupArtifact, ArtifactDeletionService $service): RedirectResponse
    {
        $this->authorize('backup_artifacts.delete');

        $identifier = $backupArtifact->original_filename ?: ('artifact-'.$backupArtifact->id);
        $data = $request->validate(['confirmation' => ['required', 'string']]);

        if (! DestructiveMode::confirmed(DestructiveMode::DELETE, $identifier, $data['confirmation'])) {
            throw ValidationException::withMessages(['confirmation' => 'Frase de confirmação incorreta.']);
        }

        $service->delete($backupArtifact, $request->user()->id, $request->ip());

        return redirect()->route('backup-artifacts.show', $backupArtifact)
            ->with('success', 'Artefato excluído com sucesso. A execução de backup permanece no histórico.');
    }
}
