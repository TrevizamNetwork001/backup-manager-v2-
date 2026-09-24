<?php

namespace App\Http\Controllers;

use App\Models\BackupArtifact;
use App\Services\ArtifactDeletionService;
use App\Support\DestructiveMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BackupArtifactController extends Controller
{
    public function index(): View
    {
        $this->authorize('backup_artifacts.view');
        $artifacts = BackupArtifact::with(['device:id,name', 'backupPolicy:id,name'])
            ->latest('id')->paginate(20);
        return view('backup-artifacts.index', compact('artifacts'));
    }

    public function show(BackupArtifact $backupArtifact, ArtifactDeletionService $service): View
    {
        $this->authorize('backup_artifacts.view');
        $backupArtifact->load(['device:id,name', 'backupPolicy:id,name', 'backupExecution.device']);

        $preview = null;
        $confirmationPhrase = null;
        if (auth()->user()->can('backup_artifacts.delete')) {
            $preview = $service->preview($backupArtifact);
            $confirmationPhrase = DestructiveMode::confirmationPhrase(DestructiveMode::DELETE, $preview->resourceLabel);
        }

        return view('backup-artifacts.show', compact('backupArtifact', 'preview', 'confirmationPhrase'));
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
