<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * Backup and restore of the single SQLite database file (Phase 16, Owner-only).
 *
 * Etivan POS is an offline, single-user desktop app whose SQLite connection uses
 * the default rollback journal (see config/database.php), so a plain file copy
 * taken from an idle menu action is a consistent snapshot. Before any restore we
 * first snapshot the current live file, so a restore can itself be undone.
 *
 * Paths are injectable so tests can point at a temporary database + directory
 * and never touch the real (or in-memory) application database.
 */
class DatabaseBackupManager
{
    /** SQLite files begin with this 16-byte magic header. */
    private const SQLITE_HEADER = "SQLite format 3\0";

    private string $databasePath;

    private string $backupDirectory;

    public function __construct(?string $databasePath = null, ?string $backupDirectory = null)
    {
        $this->databasePath = $databasePath ?? (string) config('database.connections.sqlite.database');
        $this->backupDirectory = $backupDirectory ?? storage_path('backups');
    }

    public function databasePath(): string
    {
        return $this->databasePath;
    }

    public function backupDirectory(): string
    {
        return $this->backupDirectory;
    }

    /**
     * Existing backups, newest first.
     *
     * @return list<array{name: string, size: int, created_at: int}>
     */
    public function list(): array
    {
        if (! is_dir($this->backupDirectory)) {
            return [];
        }

        $backups = [];

        foreach (glob($this->backupDirectory.'/*.sqlite') ?: [] as $path) {
            $backups[] = [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'created_at' => (int) filemtime($path),
            ];
        }

        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Copy the live database to a timestamped backup file.
     *
     * @param  string  $prefix  Distinguishes manual backups from automatic
     *                          pre-restore safety copies.
     * @return string The created backup's filename.
     */
    public function create(string $prefix = 'backup'): string
    {
        if (! is_file($this->databasePath)) {
            throw new RuntimeException('The database file does not exist.');
        }

        $this->ensureDirectory();

        $filename = $prefix.'-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6).'.sqlite';

        if (! copy($this->databasePath, $this->backupDirectory.'/'.$filename)) {
            throw new RuntimeException('Failed to write the backup file.');
        }

        return $filename;
    }

    /**
     * Restore the database from a named backup, taking an automatic pre-restore
     * safety backup of the current file first.
     */
    public function restore(string $filename): void
    {
        $source = $this->resolveBackupPath($filename);

        if (! is_file($source)) {
            throw new RuntimeException('Backup file not found.');
        }

        if (! $this->looksLikeSqlite($source)) {
            throw new RuntimeException('The selected file is not a valid SQLite database.');
        }

        // Safety net: snapshot the current database before overwriting it, so the
        // restore itself can be rolled back.
        if (is_file($this->databasePath)) {
            $this->create('pre-restore');
        }

        if (! copy($source, $this->databasePath)) {
            throw new RuntimeException('Failed to restore the database file.');
        }
    }

    /**
     * Resolve a backup filename to an absolute path inside the backup directory,
     * rejecting any attempt to escape it via path traversal.
     */
    private function resolveBackupPath(string $filename): string
    {
        // Only a bare filename is ever valid — no directory components.
        if (basename($filename) !== $filename || $filename === '' || str_contains($filename, "\0")) {
            throw new RuntimeException('Invalid backup filename.');
        }

        return $this->backupDirectory.'/'.$filename;
    }

    private function looksLikeSqlite(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, strlen(self::SQLITE_HEADER));
        fclose($handle);

        return $header === self::SQLITE_HEADER;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->backupDirectory)) {
            mkdir($this->backupDirectory, 0755, true);
        }
    }
}
