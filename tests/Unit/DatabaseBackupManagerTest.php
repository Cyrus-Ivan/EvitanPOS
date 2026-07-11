<?php

namespace Tests\Unit;

use App\Support\Backup\DatabaseBackupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseBackupManagerTest extends TestCase
{
    private string $workDir;

    private string $dbPath;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/evitan-backup-'.uniqid();
        $this->backupDir = $this->workDir.'/backups';
        $this->dbPath = $this->workDir.'/nativephp.sqlite';

        mkdir($this->workDir, 0755, true);
        // A minimal file with the SQLite magic header stands in for a real DB.
        file_put_contents($this->dbPath, "SQLite format 3\0original-data");
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workDir);

        parent::tearDown();
    }

    private function manager(): DatabaseBackupManager
    {
        return new DatabaseBackupManager($this->dbPath, $this->backupDir);
    }

    public function test_create_copies_the_database_into_the_backup_directory(): void
    {
        $filename = $this->manager()->create();

        $this->assertFileExists($this->backupDir.'/'.$filename);
        $this->assertSame(
            file_get_contents($this->dbPath),
            file_get_contents($this->backupDir.'/'.$filename),
        );
    }

    public function test_list_returns_backups_newest_first(): void
    {
        $manager = $this->manager();
        $first = $manager->create();
        touch($this->backupDir.'/'.$first, time() - 100);
        $second = $manager->create();

        $names = array_column($manager->list(), 'name');

        $this->assertSame([$second, $first], $names);
    }

    public function test_restore_takes_an_automatic_pre_restore_backup_then_swaps_contents(): void
    {
        $manager = $this->manager();

        // A backup captured while the DB held its original contents.
        $backupName = $manager->create();

        // The live DB then changes.
        file_put_contents($this->dbPath, "SQLite format 3\0changed-data");

        $manager->restore($backupName);

        // The live DB now matches the restored backup...
        $this->assertStringContainsString('original-data', (string) file_get_contents($this->dbPath));

        // ...and a pre-restore safety backup captured the changed state first.
        $preRestore = array_values(array_filter(
            $manager->list(),
            fn ($b) => str_starts_with($b['name'], 'pre-restore-'),
        ));
        $this->assertCount(1, $preRestore);
        $this->assertStringContainsString('changed-data', (string) file_get_contents($this->backupDir.'/'.$preRestore[0]['name']));
    }

    public function test_restore_rejects_path_traversal(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->restore('../nativephp.sqlite');
    }

    public function test_restore_rejects_a_missing_backup(): void
    {
        $this->expectException(RuntimeException::class);

        $this->manager()->restore('does-not-exist.sqlite');
    }

    public function test_restore_rejects_a_non_sqlite_file(): void
    {
        mkdir($this->backupDir, 0755, true);
        file_put_contents($this->backupDir.'/junk.sqlite', 'not a database');

        $this->expectException(RuntimeException::class);

        $this->manager()->restore('junk.sqlite');
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }

        rmdir($dir);
    }
}
