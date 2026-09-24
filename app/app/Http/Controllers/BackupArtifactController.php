<?php

namespace App\Http\Controllers;

use App\Models\BackupArtifact;
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

    public function show(BackupArtifact $backupArtifact): View
    {
        $this->authorize('backup_artifacts.view');
        $backupArtifact->load(['device:id,name', 'backupPolicy:id,name']);
        return view('backup-artifacts.show', compact('backupArtifact'));
    }
}
