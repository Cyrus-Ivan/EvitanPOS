<?php

namespace App\Http\Controllers;

use App\Support\Backup\DatabaseBackupManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Database backup & restore (Phase 16). Owner-only — the route sits inside the
 * `role:owner` group; the manager is resolved from the container so tests can
 * bind one pointed at temporary paths.
 */
class BackupController extends Controller
{
    public function index(DatabaseBackupManager $manager): Response
    {
        return Inertia::render('backup', [
            'backups' => array_map(fn (array $backup) => [
                'name' => $backup['name'],
                'size' => $this->formatBytes($backup['size']),
                'created_at' => date(DATE_ATOM, $backup['created_at']),
            ], $manager->list()),
        ]);
    }

    public function store(DatabaseBackupManager $manager): RedirectResponse
    {
        try {
            $manager->create();
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        // TODO(Phase 15): audit backup.created

        return back()->with('toast', ['type' => 'success', 'message' => 'Backup created.']);
    }

    public function restore(Request $request, DatabaseBackupManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string'],
        ]);

        try {
            $manager->restore($validated['filename']);
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        // TODO(Phase 15): audit backup.restored

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Database restored. A safety backup of the previous database was taken automatically.',
        ]);
    }

    /**
     * Render a byte count as a compact human-readable size.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
