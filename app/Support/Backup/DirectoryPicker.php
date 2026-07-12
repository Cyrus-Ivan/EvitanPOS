<?php

namespace App\Support\Backup;

/**
 * A native OS picker for choosing a backup destination folder or a database
 * file to restore from. Abstracted behind an interface so the backup controller
 * stays testable without the NativePHP desktop runtime (tests bind a fake).
 */
interface DirectoryPicker
{
    /**
     * Open a folder-selection dialog. Returns the chosen absolute path, or null
     * if the user cancelled.
     */
    public function pickDirectory(?string $default = null): ?string;

    /**
     * Open a file-selection dialog filtered to SQLite databases. Returns the
     * chosen absolute path, or null if the user cancelled.
     */
    public function pickFile(?string $default = null): ?string;
}
