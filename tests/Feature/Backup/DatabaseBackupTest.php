<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Support\Backup\DatabaseBackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    private string $dbPath;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/etivan-backup-feat-'.uniqid();
        $this->backupDir = $this->workDir.'/backups';
        $this->dbPath = $this->workDir.'/nativephp.sqlite';

        mkdir($this->workDir, 0755, true);
        file_put_contents($this->dbPath, "SQLite format 3\0live-data");

        // Point the controller's manager at throwaway paths so the real (and
        // in-memory test) database is never touched.
        $this->app->instance(DatabaseBackupManager::class, new DatabaseBackupManager($this->dbPath, $this->backupDir));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->workDir);

        parent::tearDown();
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

    public function test_owner_can_view_the_backup_page(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('backup'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('backups'));
    }

    public function test_owner_can_create_a_backup(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.store'))
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        $this->assertNotEmpty(glob($this->backupDir.'/backup-*.sqlite'));
    }

    public function test_owner_can_restore_and_a_pre_restore_backup_is_taken(): void
    {
        $owner = User::factory()->owner()->create();

        // Create a backup of the current live data, then change the live file.
        $this->actingAs($owner)->post(route('backup.store'));
        $backupName = basename((string) glob($this->backupDir.'/backup-*.sqlite')[0]);
        file_put_contents($this->dbPath, "SQLite format 3\0changed-data");

        $this->actingAs($owner)
            ->post(route('backup.restore'), ['filename' => $backupName])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'success');

        // Live DB restored to the backup's contents.
        $this->assertStringContainsString('live-data', (string) file_get_contents($this->dbPath));
        // A pre-restore safety backup captured the changed state first.
        $this->assertNotEmpty(glob($this->backupDir.'/pre-restore-*.sqlite'));
    }

    public function test_restore_with_a_bad_filename_reports_an_error(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('backup.restore'), ['filename' => 'does-not-exist.sqlite'])
            ->assertRedirect()
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_admin_and_cashier_are_forbidden(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->cashier()->create()] as $user) {
            $this->actingAs($user)->get(route('backup'))->assertForbidden();
            $this->actingAs($user)->post(route('backup.store'))->assertForbidden();
            $this->actingAs($user)->post(route('backup.restore'), ['filename' => 'x.sqlite'])->assertForbidden();
        }
    }
}
