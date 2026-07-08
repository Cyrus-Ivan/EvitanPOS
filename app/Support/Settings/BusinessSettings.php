<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Read/write accessor for the business-identity settings (Phase 4). These live
 * as individual key/value rows in the settings table; this class is the single
 * place that knows the key names so they aren't scattered across the codebase.
 */
class BusinessSettings
{
    /** @var list<string> */
    public const KEYS = [
        'business_name',
        'business_address',
        'tin',
        'receipt_footer',
    ];

    /**
     * Every business setting as a key => string map, defaulting missing keys to
     * an empty string so the frontend form always has a value to bind to.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = Setting::whereIn('key', self::KEYS)->pluck('value', 'key');

        return collect(self::KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => (string) ($stored[$key] ?? '')])
            ->all();
    }

    /**
     * Persist the given business settings, upserting one row per key. Only the
     * recognised KEYS are written; anything else is ignored.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach (self::KEYS as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $values[$key]],
                );
            }
        });
    }
}
