<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The single write-path into the immutable, append-only audit_logs table
 * (analogous to LoginHistoryRecorder for login_history). Every significant
 * business action funnels through record() so the action naming, actor capture,
 * and old/new payload shape stay consistent across the codebase.
 */
class AuditRecorder
{
    /**
     * Append one audit entry.
     *
     * @param  string  $action  Dotted action key, e.g. "user.role_changed".
     * @param  Model|null  $auditable  The subject of the action, linked polymorphically.
     * @param  array<string, mixed>|null  $old  Relevant field values before the change.
     * @param  array<string, mixed>|null  $new  Relevant field values after the change.
     * @param  User|null  $actor  Defaults to the authenticated user; null for system events.
     */
    public function record(
        string $action,
        ?Model $auditable = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
