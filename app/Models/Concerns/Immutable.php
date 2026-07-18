<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Enforces the append-only invariant at the model layer: a row may be inserted
 * but never updated or deleted (CLAUDE.md — immutable business history).
 *
 * Model events do not fire for bulk builder writes or raw SQL, so this guard
 * is paired with BEFORE UPDATE / BEFORE DELETE triggers in the database (see
 * the add_immutability_triggers migration) — the trait gives friendly errors
 * on the Eloquent paths, the triggers are the hard backstop.
 */
trait Immutable
{
    protected static function bootImmutable(): void
    {
        static::updating(function (Model $model): void {
            throw new RuntimeException(class_basename($model).' records are immutable and cannot be updated.');
        });

        static::deleting(function (Model $model): void {
            throw new RuntimeException(class_basename($model).' records are immutable and cannot be deleted.');
        });
    }
}
