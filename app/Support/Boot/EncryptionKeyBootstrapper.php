<?php

namespace App\Support\Boot;

/**
 * Per-install APP_KEY for packaged builds (Phase 19, F-05).
 *
 * The production bundle ships without an APP_KEY (it is stripped at build
 * time so the dev key never reaches customers). On first launch this
 * generates a key and persists it under storage/ — which the NativePHP
 * runtime maps to the per-install userData directory — so every install gets
 * its own key and every process of a launch (server, scheduler, artisan)
 * reads the same one. Dev, tests, and e2e all carry APP_KEY in the
 * environment, making this a no-op there.
 *
 * If two first-boot processes race to create the file, the atomic rename
 * makes one winner and both re-read the file afterwards, so they agree.
 */
class EncryptionKeyBootstrapper
{
    public function ensure(): void
    {
        if (! in_array(config('app.key'), [null, ''], true)) {
            return;
        }

        $file = storage_path('app/app.key');
        $key = is_file($file) ? trim((string) file_get_contents($file)) : '';

        if ($key === '') {
            $this->writeAtomically($file, 'base64:'.base64_encode(random_bytes(32)));
            $key = trim((string) file_get_contents($file));
        }

        config(['app.key' => $key]);
    }

    private function writeAtomically(string $file, string $key): void
    {
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }

        $temp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp, $key);
        chmod($temp, 0600);
        rename($temp, $file);
    }
}
