<?php

namespace Tests\Feature\Boot;

use App\Support\Boot\EncryptionKeyBootstrapper;
use Tests\TestCase;

class EncryptionKeyBootstrapperTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir().'/evitan-key-'.uniqid();
        mkdir($this->storageDir, 0755, true);
        $this->app->useStoragePath($this->storageDir);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->storageDir);

        parent::tearDown();
    }

    public function test_generates_and_persists_a_key_when_none_is_configured(): void
    {
        config(['app.key' => '']);

        (new EncryptionKeyBootstrapper)->ensure();

        $key = (string) config('app.key');
        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen((string) base64_decode(substr($key, 7), true)));
        $this->assertFileExists($this->storageDir.'/app/app.key');
        $this->assertSame($key, trim((string) file_get_contents($this->storageDir.'/app/app.key')));
    }

    public function test_reuses_the_persisted_key_across_boots(): void
    {
        config(['app.key' => '']);
        (new EncryptionKeyBootstrapper)->ensure();
        $first = (string) config('app.key');

        // Simulate the next launch: config empty again, key file present.
        config(['app.key' => '']);
        (new EncryptionKeyBootstrapper)->ensure();

        $this->assertSame($first, (string) config('app.key'));
    }

    public function test_leaves_a_configured_key_untouched(): void
    {
        config(['app.key' => 'base64:already-configured']);

        (new EncryptionKeyBootstrapper)->ensure();

        $this->assertSame('base64:already-configured', config('app.key'));
        $this->assertFileDoesNotExist($this->storageDir.'/app/app.key');
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
